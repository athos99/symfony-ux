<?php

declare(strict_types=1);

namespace App\Tests\Behat\Context;

use App\Kernel;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Kernel as HttpKernel;

final class SiteContext implements Context
{
    private ?KernelBrowser $client = null;

    private ?Crawler $crawler = null;

    #[Given("je suis sur la page d'accueil du site")]
    public function jeSuisSurLaPageDAccueilDuSite(): void
    {
        $this->client = new KernelBrowser(new Kernel('test', true));
        $this->client->catchExceptions(false);
        $this->client->request('GET', '/');
        $this->crawler = $this->client->getCrawler();
    }

    #[Then('le code de statut de la réponse devrait être :code')]
    public function leCodeDeStatutDeLaReponseDevraitEtre(string $code): void
    {
        $status = $this->client()?->getResponse()->getStatusCode();
        $expected = (int) $code;

        if ($status !== $expected) {
            throw new \RuntimeException(sprintf('Le code de statut est "%d", "%d" attendu.', $status ?? 0, $expected));
        }
    }

    #[Then('le titre de la page devrait contenir :texte')]
    public function leTitreDeLaPageDevraitContenir(string $texte): void
    {
        $title = $this->crawler()->filter('title');

        if (0 === $title->count()) {
            throw new \RuntimeException('La page ne possède aucun élément "title".');
        }

        $contenu = trim($title->text());

        if (!str_contains($contenu, $texte)) {
            throw new \RuntimeException(sprintf('Le titre "%s" ne contient pas "%s".', $contenu, $texte));
        }
    }

    #[Then('le corps de la page devrait contenir :texte')]
    public function leCorpsDeLaPageDevraitContenir(string $texte): void
    {
        $body = $this->crawler()->filter('body');

        if (0 === $body->count()) {
            throw new \RuntimeException('La page ne possède aucun élément "body".');
        }

        if (!str_contains($body->text(), $texte)) {
            throw new \RuntimeException(sprintf('Le corps de la page ne contient pas "%s".', $texte));
        }
    }

    #[Then('le favicon du site devrait être identique à :chemin')]
    public function leFaviconDuSiteDevraitEtreIdentiqueA(string $chemin): void
    {
        $icone = $this->crawler()->filter('link[rel="icon"]');

        if (0 === $icone->count()) {
            throw new \RuntimeException('La page ne déclare aucun favicon.');
        }

        $href = $icone->first()->attr('href');

        if (null === $href || '' === $href) {
            throw new \RuntimeException('Le favicon ne possède aucune URL.');
        }

        $reference = $this->client()?->getKernel()->getProjectDir().'/'.ltrim($chemin, '/');

        if (!is_file($reference)) {
            throw new \RuntimeException(sprintf('Le fichier de référence "%s" est introuvable.', $reference));
        }

        $referenceContent = file_get_contents($reference);

        if (false === $referenceContent) {
            throw new \RuntimeException(sprintf('Le fichier de référence "%s" est illisible.', $reference));
        }

        $iconPath = $this->resolvePublicPath($href);

        if (null === $iconPath) {
            throw new \RuntimeException(sprintf('Le favicon "%s" est introuvable dans le répertoire public.', $href));
        }

        $iconContent = file_get_contents($iconPath);

        if (false === $iconContent) {
            throw new \RuntimeException(sprintf('Le favicon "%s" est illisible.', $iconPath));
        }

        if (!hash_equals($referenceContent, $iconContent)) {
            throw new \RuntimeException(sprintf('Le favicon "%s" est différent de "%s".', $href, $chemin));
        }
    }

    #[Then('la locale de la page devrait être :locale')]
    public function laLocaleDeLaPageDevraitEtre(string $locale): void
    {
        $request = $this->client()?->getRequest();

        if (null === $request) {
            throw new \RuntimeException('Aucune requête n\'a été effectuée.');
        }

        if ($request->getLocale() !== $locale) {
            throw new \RuntimeException(sprintf('La locale de la requête est "%s", "%s" attendue.', $request->getLocale(), $locale));
        }

        $html = $this->crawler()->filter('html');

        if (0 === $html->count()) {
            throw new \RuntimeException('La page ne possède aucun élément "html".');
        }

        $lang = $html->attr('lang') ?? '';
        $expected = str_replace('_', '-', $locale);

        if (0 !== strcasecmp($lang, $expected)) {
            throw new \RuntimeException(sprintf('La langue HTML est "%s", "%s" attendue.', $lang, $expected));
        }
    }

    #[Then('le site devrait utiliser Tailwind CSS')]
    public function leSiteDevraitUtiliserTailwindCss(): void
    {
        $css = $this->stylesheetContents();

        if ('' === $css) {
            throw new \RuntimeException('Aucune feuille de style du build n\'a pu être chargée.');
        }

        if (!str_contains($css, '@layer theme') || !str_contains($css, '@layer utilities')) {
            throw new \RuntimeException('La feuille de style ne contient pas les couches générées par Tailwind CSS.');
        }
    }

    #[Then('les assets devraient être servis depuis le répertoire :chemin')]
    public function lesAssetsDevraientEtreServisDepuisLeRepertoire(string $chemin): void
    {
        $prefix = rtrim($chemin, '/').'/';

        $stylesheet = $this->crawler()->filter('link[rel="stylesheet"][href*="'.$prefix.'"]');
        $script = $this->crawler()->filter('script[type="module"][src*="'.$prefix.'"]');

        if (0 === $stylesheet->count()) {
            throw new \RuntimeException(sprintf('Aucune feuille de style n\'est servie depuis "%s".', $chemin));
        }

        if (0 === $script->count()) {
            throw new \RuntimeException(sprintf('Aucun module JavaScript n\'est servi depuis "%s".', $chemin));
        }
    }

    #[Then('la version de PHP devrait être au moins :version')]
    public function laVersionDePhpDevraitEtreAuMoins(string $version): void
    {
        $this->assertVersionAtLeast(PHP_VERSION, $version, 'PHP');
    }

    #[Then('la version de Symfony devrait être au moins :version')]
    public function laVersionDeSymfonyDevraitEtreAuMoins(string $version): void
    {
        $this->assertVersionAtLeast(HttpKernel::VERSION, $version, 'Symfony');
    }

    #[Then('le corps de la page doit utiliser la couleur de base du thème')]
    public function leCorpsDeLaPageDoitUtiliserLaCouleurDeBaseDuTheme(): void
    {
        $body = $this->crawler()->filter('body');

        if (0 === $body->count()) {
            throw new \RuntimeException('La page ne possède aucun élément "body".');
        }

        $classes = $body->attr('class') ?? '';

        if (1 !== preg_match('/(?:^|\s)bg-base-100(?:\s|$)/', $classes)) {
            throw new \RuntimeException(sprintf('Le corps de la page n\'utilise pas la couleur de base du thème (classes : "%s").', $classes));
        }
    }

    #[Then('la couleur de fond doit être un beige très clair')]
    public function laCouleurDeFondDoitEtreUnBeigeTresClair(): void
    {
        $colors = $this->themeVariable('--color-base-100');

        if ([] === $colors) {
            throw new \RuntimeException('La couleur de base du thème "--color-base-100" est introuvable.');
        }

        $color = trim((string) end($colors));

        if (1 !== preg_match('/^#([0-9a-f]{6})$/i', $color, $hex)) {
            throw new \RuntimeException(sprintf('La couleur de base du thème "%s" n\'est pas un code hexadécimal exploitable.', $color));
        }

        $red = (int) hexdec(substr($hex[1], 0, 2));
        $green = (int) hexdec(substr($hex[1], 2, 2));
        $blue = (int) hexdec(substr($hex[1], 4, 2));

        if ($red < 230 || $green < 230 || $blue < 230) {
            throw new \RuntimeException(sprintf('La couleur de base "%s" n\'est pas assez claire.', $color));
        }

        if ($red < $green || $green < $blue || $red - $blue < 3) {
            throw new \RuntimeException(sprintf('La couleur de base "%s" n\'est pas un beige.', $color));
        }
    }

    #[Then('la page devrait déclarer une balise viewport adaptée au responsive')]
    public function laPageDevraitDeclarerUneBaliseViewportAdapteeAuResponsive(): void
    {
        $viewport = $this->crawler()->filter('meta[name="viewport"]');

        if (0 === $viewport->count()) {
            throw new \RuntimeException('La page ne déclare aucune balise "meta[name=viewport]".');
        }

        $content = $viewport->first()->attr('content') ?? '';

        if (!str_contains($content, 'width=device-width')) {
            throw new \RuntimeException(sprintf('La balise viewport "%s" n\'est pas adaptée au responsive.', $content));
        }
    }

    #[AfterScenario]
    public function shutdownKernel(): void
    {
        if ($this->client instanceof KernelBrowser) {
            $this->client->getKernel()->shutdown();
        }

        $this->client = null;
        $this->crawler = null;
    }

    private function client(): ?KernelBrowser
    {
        return $this->client;
    }

    private function crawler(): Crawler
    {
        if (!$this->crawler instanceof Crawler) {
            throw new \RuntimeException('Aucune page du site n\'a été ouverte.');
        }

        return $this->crawler;
    }

    /**
     * @return list<string>
     */
    private function themeVariable(string $name): array
    {
        preg_match_all('/'.preg_quote($name, '/').'\s*:\s*([^;]+)/', $this->stylesheetContents(), $matches);

        return $matches[1];
    }

    private function stylesheetContents(): string
    {
        $contents = [];

        foreach ($this->crawler()->filter('link[rel="stylesheet"]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $href = $node->getAttribute('href');

            if ('' === $href) {
                continue;
            }

            $path = $this->resolvePublicPath($href);

            if (null === $path) {
                continue;
            }

            $css = file_get_contents($path);

            if (false !== $css) {
                $contents[] = $css;
            }
        }

        return implode("\n", $contents);
    }

    private function assertVersionAtLeast(string $actual, string $minimum, string $label): void
    {
        if (version_compare($actual, $minimum, '<')) {
            throw new \RuntimeException(sprintf('La version %s est "%s", "%s" ou supérieure attendue.', $label, $actual, $minimum));
        }
    }

    private function resolvePublicPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $projectDir = $this->client()?->getKernel()->getProjectDir();

        if (null === $projectDir) {
            return null;
        }

        $candidate = $projectDir.'/public/'.ltrim($path, '/');

        return is_file($candidate) ? $candidate : null;
    }
}
