digestdigestforum
===========

A fork of the Moodle forum code, this digestforum will require that all posts be added to a digest, 
regardless of the users' settings.

Modified by Marty Gilbert (martygilbert at gmail)

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
