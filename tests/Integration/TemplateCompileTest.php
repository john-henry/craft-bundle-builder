<?php

use Twig\Source;

// ---------------------------------------------------------------------------
// CP template compile smoke test
// ---------------------------------------------------------------------------
// What the bundle editor does in a browser is browser territory, but a template
// that will not compile takes its whole page down and nothing else in this suite
// would notice. Tokenising and parsing every template under Craft's own Twig
// environment catches a syntax slip without any page context.

describe('Plugin CP templates', function() {
    it('compiles every template without a syntax error', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-bundle-builder/src/templates';

        // CP-only Twig functions (actionInput and the rest) only exist in the
        // control panel environment, which is what these templates render under.
        $view = Craft::$app->getView();
        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
        $twig = $view->getTwig();

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $checked = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $name = substr($file->getPathname(), strlen($templatesPath) + 1);
            $twig->parse($twig->tokenize(new Source(file_get_contents($file->getPathname()), $name)));
            $checked[] = str_replace('\\', '/', $name);
        }

        expect($checked)->toContain('bundles/index.twig')
            ->and($checked)->toContain('bundles/_product-row.twig')
            ->and($checked)->toContain('bundle-types/index.twig')
            ->and($checked)->toContain('bundle-types/_edit.twig')
            ->and($checked)->toContain('_fields/components.twig');
    });

    // Compiling cannot catch a missing {% import %}: Twig only raises "Variable
    // does not exist" at render time, so a template using forms.* without the
    // import ships broken and looks fine here. Require the import statically in
    // any template that reaches for an alias.
    it('imports the macros and forms wherever they are used', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-bundle-builder/src/templates';

        $aliases = [
            'forms' => '/import\s+[\'"]_includes\/forms(\.twig)?[\'"]\s+as\s+forms/',
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $missing = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen($templatesPath) + 1);

            foreach ($aliases as $alias => $importPattern) {
                if (preg_match('/\b' . $alias . '\./', $source) && !preg_match($importPattern, $source)) {
                    $missing[] = "{$name} uses {$alias}. without importing it";
                }
            }
        }

        expect($missing)->toBe([]);
    });

    // House rule: commentary belongs in PHP, JS or the docs, never in a plugin
    // template.
    it('carries no Twig comments', function() {
        $templatesPath = Craft::getAlias('@root') . '/plugins/craft-bundle-builder/src/templates';

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templatesPath));
        $offenders = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            // The whole comment, opener and closer both. A bare `{#` also turns
            // up inside an ICU plural (`other{# hops}`), which is a message
            // format and not commentary.
            if (preg_match('/\{#.*?#\}/s', (string)file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($templatesPath) + 1);
            }
        }

        expect($offenders)->toBe([]);
    });
});
