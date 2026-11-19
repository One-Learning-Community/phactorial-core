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

```
composer require phactorial/phactorial
```

```php
use Phactorial\MathRender;

echo MathRender::fromTex('x = \frac{-b \pm \sqrt{b^2 - 4ac}}{2a}')->display()->svg(pad: 1);
```

Every glyph is a path, so the SVG needs no web font. Inline SVG sits on the text's baseline, and every SVG has an
`aria-label` with the formula as text. For other formats, end the call with `png()`, `mathml()`, `omml()`, `tex()` or
`text()`.

Chemistry works the same way:

```php
echo MathRender::fromTex('\ce{CH4 + 2O2 -> CO2 + 2H2O}')->svg();
```

- Reads the TeX math commands KaTeX and MathJax support, and MathML from equation editors, Word and the web.
- Reads mhchem chemistry (`\ce`, `\pu`), which reaches Word and MathML as real equations.
- Converts TeX to MathML, MathML to TeX, and either to Word.
- Never throws on input. Fixed limits keep hostile formulas cheap, and anything not drawn as written is reported.
- About 0.1 ms per formula once the font is loaded; about 15 ms for the first formula in a new process without opcache.

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

`tests/Fixtures.php` holds the sample formulas. Fixtures named `*-inline` use text style; the rest use display style.

Operator spacing and stretching come from the W3C MathML Core operator dictionary. `bin/gen-operators.php` generates
`resources/operators.php` and records the snapshot URL in its header.

## Credits

The layout design follows [ziamath](https://github.com/cdelker/ziamath) (MIT). The bundled STIX Two Math is under the
SIL Open Font License 1.1 (`resources/fonts/OFL.txt`).
