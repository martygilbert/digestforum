# digestforum

A fork of the Moodle forum code, this digestforum will require that all posts be added to a digest, 
regardless of the users' settings. One email per day, per forum will be sent to
subscribers.

Modified by Marty Gilbert (martygilbert at gmail)

## Moodle 5.3
Total rewrite to support Moodle 5.3. Started with the mod_forum code, and "digested" it.

## Moodle 5.2
DIGEST_52_STABLE is the 5.3 rewrite with core mod_forum's 5.3 changes taken back out, so it
matches the Moodle 5.2 forum. Keep its version numbers below DIGEST_53_STABLE's, so a site can
upgrade from Moodle 5.2 to 5.3.

## Moodle 5.1
DIGEST_51_STABLE is DIGEST_52_STABLE with core mod_forum's 5.2 API changes taken back out,
mainly the report builder entities and a few test helpers, so it runs on Moodle 5.1. Keep its
version numbers at or below DIGEST_52_STABLE's, so a site can upgrade from Moodle 5.1 to 5.2.

## Moodle 3.6
CHANGE FOR DIGEST_36 - Forked the Moodle 3.6 forum code and re-digestforumed it. 

```
find . -type f ! -name 'README.md' ! -path './.git/*' -exec sed -i 's/forum/digestforum/g' '{}' \;
find . -type f ! -name 'README.md' ! -path './.git/*' -exec sed -i 's/FORUM/DFORUM/g' '{}' \;
find . -type f ! -name 'README.md' ! -path './.git/*' -exec sed -i 's/trackdigestforums/trackforums/g' '{}' \;
```

trackforums is a field in the mdl_user table; don't want to 'digest' it.

Pay special attention to the module name strings (singular AND plural) in the lang files.

NOTE from DIGEST_31_STABLE:
When forking the forum code, make sure to change the names of the mustache templates. Only
took me 3+ days to do this. 
