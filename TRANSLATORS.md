# Translator Info

If you like phpPgAdmin, then why not translate it into your native language?

There are quite a large number of strings to be translated. Partial
translations are better than no translations at all. As a rough guide, the
strings are ordered from most important to least important in the language
file. You can ask the developers mailing list if you don't know what a
certain string means.

phpPgAdmin uses UTF-8 for translations. Always work with UTF-8 files when
creating a new translation or editing an existing one.

## Create a new translation

1. Go to the `lang/` subdirectory.
2. Copy `english.php` to `yourlanguage.php`.
3. Update the comment at the top of the file. Put yourself as the language
   maintainer. Edit the `applang` variable and put your language's name in it,
   in your language. Edit the `applocale` and put your language code according
   to the standard: http://www.ietf.org/rfc/rfc1766.txt

    Basically, you just need to put your language code and optionally the
    country code separated by a `-`. Example for French (Canadian): `fr-CA`.

    References:
    - http://www.w3.org/WAI/ER/IG/ert/iso639.htm
    - http://www.iso.org/iso/country_codes/iso_3166_code_lists/country_names_and_code_elements.htm

4. Go through as much of the rest of the file as you wish, replacing the
   English strings with strings in your native language.

At this point you can send the `yourlanguage.php` file to the project and
the maintainers will help with testing and recoding if necessary. Only do
that if you find the rest of these steps too difficult.

## Add your language to phpPgAdmin

5. Edit `lang/translations.php` and add your language to the `$appLangFiles`
   array. Also add your language to the `$availableLanguages` array for
   browser auto-detection.

6. Send your contribution (the `lang/translations.php` entry and the
   `lang/yourlanguage.php` file) to the developers mailing list:

```
phppgadmin-devel@lists.sourceforge.net
```

## Tools

There is a tool named `langcheck` in the `lang/` directory. To run it:

```bash
php langcheck <language>
```

It reports which strings are missing from your language file and which need
to be deleted.

Thank you for your contribution — you have just made phpPgAdmin accessible
to thousands more users!
