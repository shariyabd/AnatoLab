<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * `docs/asset-register.md`, ready to render (handover 02, surfaced by 14).
 *
 * The attribution page is a Definition-of-Done item for handover 02 — "attribution
 * strings surfaced in the About page (F14)", `docs/asset-register.md` §6 — and the
 * register is the single source of truth for what every asset is and what may be
 * done with it. So the page renders that file rather than restating it: a
 * duplicated attribution list is one that goes stale the first time a row changes,
 * and going stale here means claiming a right we do not have.
 *
 * Reads the file, converts it, and returns strings. It touches no request, session
 * or user (invariant 1), which is what lets the controller stay thin and the page
 * stay a template (invariants 7 and 8).
 *
 * **The HTML is not trusted output of user input.** The source is a repository file,
 * but it is converted with raw HTML stripped and unsafe link schemes disabled
 * anyway, because "it is only our own file" is exactly the assumption that stops
 * being true the day the register quotes a licence text someone pasted in.
 */
final class AssetRegisterDocument
{
    /**
     * Path relative to the application base, so the page renders the register
     * that shipped with this build rather than a copy someone remembered to update.
     */
    private const PATH = 'docs/asset-register.md';

    /**
     * The register, as sanitised HTML, with the release gate's current state.
     *
     * @return array{html: string, updatedAt: string|null, assetsCleared: bool, modelCount: int}
     *
     * @throws RuntimeException when the register is missing from the build
     */
    public function render(): array
    {
        $markdown = $this->read();

        return [
            'html' => $this->demoteHeadings(Str::markdown($markdown, [
                // Our own file today; not necessarily only our own prose forever.
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ])),
            'updatedAt' => $this->updatedAt($markdown),
            ...$this->gate(),
        ];
    }

    /**
     * The release gate, read from the manifest rather than from prose.
     *
     * `public/models/manifest.json` is what `scripts/verify-models.mjs --release`
     * checks, so reading the same file means the page cannot claim assets are
     * cleared while the verifier says they are not (docs/asset-register.md §6).
     *
     * @return array{assetsCleared: bool, modelCount: int}
     */
    private function gate(): array
    {
        $path = public_path('models/manifest.json');

        if (! is_file($path)) {
            return ['assetsCleared' => false, 'modelCount' => 0];
        }

        $contents = file_get_contents($path);
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest)) {
            return ['assetsCleared' => false, 'modelCount' => 0];
        }

        $models = $manifest['models'] ?? [];

        return [
            'assetsCleared' => ($manifest['status'] ?? null) === 'cleared',
            'modelCount' => is_array($models) ? count($models) : 0,
        ];
    }

    /**
     * Push every heading in the document down one level.
     *
     * The register opens with `# Asset Register`, and the page it is embedded
     * in already has its own `<h1>`. Two `<h1>`s on one page is not a style
     * preference — a screen-reader user navigating by heading gets two
     * competing answers to "what is this page", and the register's outline
     * stops being a sub-outline of the page (SC 1.3.1, SC 2.4.6).
     *
     * `h6` has nowhere to go and stays where it is; the register does not use
     * one, and clamping is better than emitting an `h7` that means nothing.
     */
    private function demoteHeadings(string $html): string
    {
        $replacements = [];

        for ($level = 5; $level >= 1; $level--) {
            $replacements["<h{$level}>"] = '<h'.($level + 1).'>';
            $replacements["</h{$level}>"] = '</h'.($level + 1).'>';
        }

        return strtr($html, $replacements);
    }

    private function read(): string
    {
        $path = base_path(self::PATH);

        // Loud rather than an empty page: an attribution page that renders
        // nothing looks like "there is nothing to attribute", which is the one
        // thing it must never say (PRD §42).
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                self::PATH.' is missing from this build, so attribution cannot be shown.'
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(self::PATH.' could not be read.');
        }

        return $contents;
    }

    /**
     * The register's own "Last updated" line, so the page dates the content it
     * is showing rather than the moment it was rendered.
     */
    private function updatedAt(string $markdown): ?string
    {
        if (preg_match('/\*\*Last updated:\*\*\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/', $markdown, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
