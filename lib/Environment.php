<?php

declare(strict_types=1);

namespace Doctrine\RST;

use Doctrine\RST\Meta\MetaEntry;
use Doctrine\RST\Meta\Metas;
use Doctrine\RST\NodeFactory\NodeFactory;
use Doctrine\RST\References\Reference;
use Doctrine\RST\References\ResolvedReference;
use Doctrine\RST\Templates\TemplateRenderer;
use InvalidArgumentException;
use function array_shift;
use function dirname;
use function iconv;
use function implode;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

class Environment
{
    /** @var Configuration */
    private $configuration;

    /** @var ErrorManager */
    private $errorManager;

    /** @var UrlGenerator */
    private $urlGenerator;

    /** @var int */
    private $currentTitleLevel = 0;

    /** @var string[] */
    private $titleLetters = [];

    /** @var string */
    private $currentFileName = '';

    /** @var string */
    private $currentDirectory = '.';

    /** @var string */
    private $targetDirectory = '.';

    /** @var string|null */
    private $url = null;

    /** @var Reference[] */
    private $references = [];

    /** @var Metas */
    private $metas;

    /** @var string[] */
    private $dependencies = [];

    /** @var string[] */
    private $unresolvedDependencies = [];

    /** @var string[] */
    private $variables = [];

    /** @var string[] */
    private $links = [];

    /** @var int[] */
    private $levels = [];

    /** @var int[] */
    private $counters = [];

    /** @var string[] */
    private $anonymous = [];

    /** @var InvalidLink[] */
    private $invalidLinks = [];

    public function __construct(
        Configuration $configuration,
        string $currentFileName,
        Metas $metas,
        string $currentDirectory,
        string $targetDirectory,
        ErrorManager $errorManager
    )
    {
        $this->configuration = $configuration;
        $this->currentFileName = $currentFileName;
        $this->metas = $metas;
        $this->currentDirectory = $currentDirectory;
        $this->targetDirectory = $targetDirectory;
        $this->errorManager  = $errorManager;
        $this->urlGenerator  = new UrlGenerator(
            $this->configuration
        );

        $this->reset();
    }

    public function reset() : void
    {
        $this->titleLetters      = [];
        $this->currentTitleLevel = 0;
        $this->levels            = [];
        $this->counters          = [];

        for ($level = 0; $level < 16; $level++) {
            $this->levels[$level]   = 1;
            $this->counters[$level] = 0;
        }
    }

    public function getConfiguration() : Configuration
    {
        return $this->configuration;
    }

    public function getErrorManager() : ErrorManager
    {
        return $this->errorManager;
    }

    public function getNodeFactory() : NodeFactory
    {
        return $this->configuration->getNodeFactory($this);
    }

    public function getTemplateRenderer() : TemplateRenderer
    {
        return $this->configuration->getTemplateRenderer();
    }

    public function registerReference(Reference $reference) : void
    {
        $this->references[$reference->getName()] = $reference;
    }

    public function resolve(string $section, string $data) : ?ResolvedReference
    {
        if (! isset($this->references[$section])) {
            throw new InvalidArgumentException(sprintf('Unknown reference section %s', $section));
        }

        $reference = $this->references[$section];

        $resolvedReference = $reference->resolve($this, $data);

        if ($resolvedReference === null) {
            $this->addInvalidLink(new InvalidLink($data));
            $this->getMetaEntry()->removeDependency($data);

            return null;
        }

        if (isset($this->unresolvedDependencies[$data])) {
            $this->getMetaEntry()->resolveDependency(
                $this->unresolvedDependencies[$data],
                $resolvedReference->getFile()
            );
        }

        return $resolvedReference;
    }

    public function addInvalidLink(InvalidLink $invalidLink) : void
    {
        $this->invalidLinks[] = $invalidLink;
    }

    /**
     * @return InvalidLink[]
     */
    public function getInvalidLinks() : array
    {
        return $this->invalidLinks;
    }

    /**
     * @return string[]|null
     */
    public function found(string $section, string $data) : ?array
    {
        if (isset($this->references[$section])) {
            $reference = $this->references[$section];

            $reference->found($this, $data);

            return null;
        }

        $this->errorManager->error('Unknown reference section ' . $section);

        return null;
    }

    /**
     * @param mixed $value
     */
    public function setVariable(string $variable, $value) : void
    {
        $this->variables[$variable] = $value;
    }

    public function createTitle(int $level) : string
    {
        for ($currentLevel = 0; $currentLevel < 16; $currentLevel++) {
            if ($currentLevel <= $level) {
                continue;
            }

            $this->levels[$currentLevel]   = 1;
            $this->counters[$currentLevel] = 0;
        }

        $this->levels[$level] = 1;
        $this->counters[$level]++;
        $token = ['title'];

        for ($i = 1; $i <= $level; $i++) {
            $token[] = $this->counters[$i];
        }

        return implode('.', $token);
    }

    public function getNumber(int $level) : int
    {
        return $this->levels[$level]++;
    }

    /**
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function getVariable(string $variable, $default = null)
    {
        if (isset($this->variables[$variable])) {
            return $this->variables[$variable];
        }

        return $default;
    }

    public function setLink(string $name, string $url) : void
    {
        $name = trim(strtolower($name));

        if ($name === '_') {
            $name = array_shift($this->anonymous);
        }

        $this->links[$name] = trim($url);
    }

    public function resetAnonymousStack() : void
    {
        $this->anonymous = [];
    }

    public function pushAnonymous(string $name) : void
    {
        $this->anonymous[] = trim(strtolower($name));
    }

    /**
     * @return string[]
     */
    public function getLinks() : array
    {
        return $this->links;
    }

    public function getLink(string $name, bool $relative = true) : string
    {
        $name = trim(strtolower($name));

        if (isset($this->links[$name])) {
            $link = $this->links[$name];

            if ($relative) {
                return (string) $this->relativeUrl($link);
            }

            return $link;
        }

        return '';
    }

    public function addDependency(string $dependency, bool $requiresResolving = false) : void
    {
        $dependency = $this->canonicalUrl($dependency);

        if ($dependency === null) {
            throw new InvalidArgumentException(sprintf(
                'Could not get canonical url for dependency %s',
                $dependency
            ));
        }

        if ($requiresResolving) {
            // a hack to avoid collisions between resolved and unresolved dependencies
            $dependencyName = 'UNRESOLVED__'.$dependency;
            $this->unresolvedDependencies[$dependency] = $dependencyName;
        } else {
            $dependencyName = $dependency;
        }

        if (!in_array($dependencyName, $this->dependencies)) {
            $this->dependencies[] = $dependencyName;
        }
    }

    /**
     * @return string[]
     */
    public function getDependencies() : array
    {
        return $this->dependencies;
    }

    public function relativeUrl(?string $url) : ?string
    {
        return $this->urlGenerator->relativeUrl($url, $this->currentFileName);
    }

    public function absoluteUrl(string $url) : string
    {
        return $this->urlGenerator->absoluteUrl($this->getDirName(), $url);
    }

    public function canonicalUrl(string $url) : ?string
    {
        return $this->urlGenerator->canonicalUrl($this->getDirName(), $url);
    }

    public function generateUrl(string $path) : string
    {
        return $this->urlGenerator->generateUrl(
            $path,
            $this->currentFileName,
            $this->getDirName()
        );
    }

    public function getDirName() : string
    {
        $dirname = dirname($this->currentFileName);

        if ($dirname === '.') {
            return '';
        }

        return $dirname;
    }

    public function getCurrentFileName() : string
    {
        return $this->currentFileName;
    }

    public function getCurrentDirectory() : string
    {
        return $this->currentDirectory;
    }

    public function absoluteRelativePath(string $url) : string
    {
        return $this->currentDirectory . '/' . $this->getDirName() . '/' . $this->relativeUrl($url);
    }

    public function getTargetDirectory() : string
    {
        return $this->targetDirectory;
    }

    public function getUrl() : string
    {
        if ($this->url !== null) {
            return $this->url;
        }

        return $this->currentFileName;
    }

    public function setUrl(string $url) : void
    {
        if ($this->getDirName() !== '') {
            $url = $this->getDirName() . '/' . $url;
        }

        $this->url = $url;
    }

    public function getMetas() : Metas
    {
        return $this->metas;
    }

    public function getMetaEntry() : ?MetaEntry
    {
        return $this->metas->get($this->currentFileName);
    }

    public function getLevel(string $letter) : int
    {
        foreach ($this->titleLetters as $level => $titleLetter) {
            if ($letter === $titleLetter) {
                return $level;
            }
        }

        $this->currentTitleLevel++;
        $this->titleLetters[$this->currentTitleLevel] = $letter;

        return $this->currentTitleLevel;
    }

    /**
     * @return string[]
     */
    public function getTitleLetters() : array
    {
        return $this->titleLetters;
    }

    public static function slugify(string $text) : string
    {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        // transliterate
        $text = (string) iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, '-');

        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // lowercase
        $text = strtolower($text);

        return $text;
    }
}
