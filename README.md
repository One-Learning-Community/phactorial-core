<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://github.com/One-Learning-Community/phactorial/raw/main/site/src/.vuepress/public/phactorial-wordmark-dark.svg">
    <img alt="Phactorial" src="https://github.com/One-Learning-Community/phactorial/raw/main/site/src/.vuepress/public/phactorial-wordmark.svg" height="56">
  </picture>
</h1>

**Equations made simple.**

[![phactorial/phactorial](https://img.shields.io/packagist/v/phactorial/phactorial?label=phactorial/phactorial)](https://packagist.org/packages/phactorial/phactorial) [![core](https://github.com/One-Learning-Community/phactorial/actions/workflows/core.yml/badge.svg?branch=main)](https://github.com/One-Learning-Community/phactorial/actions/workflows/core.yml)

Math typesetting in plain PHP. It reads TeX or MathML and writes SVG, PNG, MathML, TeX, Word equations (OMML) or plain
text. It needs PHP 8.4. No Node, no browser, no fonts on the server.

Quoin uses it for equations in PDF, Word and HTML documents.

phpoffice/math converts between MathML and Word's format; Phactorial also reads TeX and draws the formula.

```
composer require phactorial/phactorial
```

```php
use Phactorial\MathRender;

echo MathRender::fromTex('x = \frac{-b \pm \sqrt{b^2 - 4ac}}{2a}')->display()->svg(pad: 1);
```

Every glyph is a path, so the SVG needs no web font. Inline, it carries the `vertical-align` that sets it on the line's
baseline, and every SVG has an `aria-label` of the formula as text. The same call ends in `png()`, `mathml()`,
`omml()`, `tex()` or `text()`.

Chemistry reads the same way. The TeX writer writes `\ce` back as read, so TeX that leaves this package needs mhchem, which KaTeX and MathJax offer.

```php
echo MathRender::fromTex('\ce{CH4 + 2O2 -> CO2 + 2H2O}')->svg();
```

- Reads what KaTeX reads, and MathML from equation editors, Word and the web.
- Reads chemistry (`\ce`, `\pu` from mhchem) as ordinary nodes, so it reaches Word and MathML as real equations.
- Converts TeX to MathML, MathML to TeX, either to Word.
- Never throws on input. Fixed limits keep hostile formulas cheap, and anything not drawn as written is reported.
- About 0.1 ms per formula once the font is loaded, and about 15 ms for the first formula in a new process without opcache.

## Documentation

- [Overview](https://one-learning-community.github.io/phactorial/), with a comparison to KaTeX and MathJax
- [Recipes](https://one-learning-community.github.io/phactorial/recipes.html): web pages, email, Word, conversion,
  alt text, untrusted input
- [Input](https://one-learning-community.github.io/phactorial/input.html): the builder, TeX, MathML, notes and limits
- [Output](https://one-learning-community.github.io/phactorial/output.html): one-call output, layout options and every writer
- [Custom backends](https://one-learning-community.github.io/phactorial/backends.html): the display list, glyph
  outlines, fonts

## Testing

```
vendor/bin/phpunit
composer analyse
php tests/render-fixtures.php   # writes tests/out/*.svg, .png (needs rsvg-convert) and index.html
```

`tests/Fixtures.php` holds the sample formulas. A fixture name ending in `-inline` is laid out in text style; all
others use display style.

Spacing, stretching and large-operator behavior come from the W3C MathML Core operator dictionary. The snapshot used
is recorded in the header of `resources/operators.php`, which `bin/gen-operators.php` generates.

## Credits

The layout design follows [ziamath](https://github.com/cdelker/ziamath) (MIT). The bundled STIX Two Math is under the
SIL Open Font License 1.1 (`resources/fonts/OFL.txt`).
