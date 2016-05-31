# Drupal 8 - Project LDAP 
Mirror of the extension Drupal Project LDAP 

Branches not suffixed by "-dev":
- have upstream on Drupal module Project LDAP (https://git.drupal.org/project/ldap.git)
- are mirrors of the very same named branch of the project
- only modified by team Project LDAP

Branches suffixed by "-dev" are the resulted merge of the homologous upstream branch and developments from here

To compare what is done/changed by far from oficial and non-oficial branch ...
```
- git clone git@github.com:7rin0/project-ldap-drupal-8.git
- git remote add upstream git://git.drupal.org/project/ldap.git
- git diff upstream/8.x-3.x 8.x-3.x-dev
```
where upstream/8.x-3.x (first branch) represents the oficial one at drupal repository and 8.x-3.x-dev (second one) represents developments being done here

Every time the Project LDAP extension is updated th eevry same is merged here and it is given total priority to all changes being done by the Drupal community
