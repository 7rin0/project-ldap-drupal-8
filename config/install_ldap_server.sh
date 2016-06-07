#!/bin/bash

# Usage
# First create an encrypted password to be used as connection pass
# and pass as a parameter with the script. To create use: slappasswd
sudo yum clean all -y

# Install openldap as service manager
sudo yum -y install openldap-servers openldap-clients
# Install php ldap module
sudo yum install php70w-ldap --skip-broken -y

# If config is not set get new one
if [ ! -f /var/lib/ldap/DB_CONFIG ]; then
	sudo cp /usr/share/openldap-servers/DB_CONFIG.example /var/lib/ldap/DB_CONFIG
fi

# If no pass defined do not execute script
if [[ -z $1 ]]; then
cat << EOF
= = = = = = = = = =
You must pass a password as first parameter!
Use the command "slappasswd" to generate a password and pass as first parameter along this script
= = = = = = = = = =
EOF
	exit;
fi

# Add user ldap to group
sudo chown ldap. /var/lib/ldap/DB_CONFIG

# Start and restart ldap and apache service
sudo systemctl start slapd
sudo systemctl enable slapd
sudo service httpd restart

# Add root to LDAP Server
define(){ IFS='\n' read -r -d '' ${1} || true; }
define LDAP_ROOT << EOF
dn: olcDatabase={0}config,cn=config
changetype: modify
replace: olcRootPW
olcRootPW: $1
EOF

# Move to tmp file because badlt support of stdin on ldap commands
echo "$LDAP_ROOT" > chrootpw.ldif
sudo ldapadd -Y EXTERNAL -H ldapi:/// -f chrootpw.ldif

# Import basic schemas
sudo ldapadd -Y EXTERNAL -H ldapi:/// -f /etc/openldap/schema/cosine.ldif
sudo ldapadd -Y EXTERNAL -H ldapi:/// -f /etc/openldap/schema/cosine.ldif
sudo ldapadd -Y EXTERNAL -H ldapi:/// -f /etc/openldap/schema/inetorgperson.ldif

# Add domain to LDAP Server
define LDAP_DOMAIN << EOF
dn: olcDatabase={1}monitor,cn=config
changetype: modify
replace: olcAccess
olcAccess: {0}to * by dn.base="gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth"
  read by dn.base="cn=Manager,dc=ldap,dc=vm" read by * none

dn: olcDatabase={2}hdb,cn=config
changetype: modify
replace: olcSuffix
olcSuffix: dc=ldap,dc=vm

dn: olcDatabase={2}hdb,cn=config
changetype: modify
replace: olcRootDN
olcRootDN: cn=Manager,dc=ldap,dc=vm

dn: olcDatabase={2}hdb,cn=config
changetype: modify
replace: olcRootPW
olcRootPW: $1

dn: olcDatabase={2}hdb,cn=config
changetype: modify
replace: olcAccess
olcAccess: {0}to attrs=userPassword,shadowLastChange by
  dn="cn=Manager,dc=ldap,dc=vm" write by anonymous auth by self write by * none
olcAccess: {1}to dn.base="" by * read
olcAccess: {2}to * by dn="cn=Manager,dc=ldap,dc=vm" write by * read
EOF

# Move to tmp file because ldap support of stdin on ldap commands
echo "$LDAP_DOMAIN" > chdomain.ldif
sudo ldapadd -Y EXTERNAL -H ldapi:/// -f chdomain.ldif

# Add root to LDAP Server
define LDAP_BASE_DOMAIN << EOF
dn: dc=ldap,dc=vm
objectClass: top
objectClass: dcObject
objectclass: organization
o: Server LDAP
dc: LDAP

dn: cn=MANAGER,dc=ldap,dc=vm
objectClass: organizationalRole
cn: MANAGER
description: Directory Manager

dn: ou=SITE,dc=ldap,dc=vm
objectClass: organizationalUnit
ou: SITE

dn: ou=LAPLACE,dc=ldap,dc=vm
objectClass: organizationalUnit
ou: LAPLACE
EOF

# Move to tmp file because badlt support of stdin on ldap commands
echo "$LDAP_BASE_DOMAIN" > basedomain.ldif
sudo ldapadd -x -D cn=Manager,dc=ldap,dc=vm -W -f basedomain.ldif

# Ajout fake user DELETE ON PROD
define LDAP_FAKE_USER << EOF
dn: cn=ldapdev,dc=ldap,dc=vm
objectClass: inetOrgPerson
sn: Nom
cn: ldapdev
title: irxarmuser
uid: 98785
userPassword: ldappassword
mail: user@ldap.dev
displayName: Display Prenom
telephoneNumber: 12345689

dn: cn=ldapdevgroup,ou=PLACE,dc=ldap,dc=vm
objectClass: inetOrgPerson
sn: Nom
cn: ldapdevgroup
title: irxarmusergroup
uid: 98785
userPassword: ldappassword
mail: usergroup@ldap.dev
displayName: Display Prenom Group
telephoneNumber: 12345689
EOF

# ADD Fake user to GROUP LAPLACE
echo "$LDAP_FAKE_USER" > fakeuser.ldif
sudo ldapadd -x -D cn=Manager,dc=ldap,dc=vm -W -f fakeuser.ldif

### INSTAL LDAPADMINi
### FOLLOW HERE http://www.server-world.info/en/note?os=CentOS_7&p=openldap&f=7
sudo yum --enablerepo=epel -y install phpldapadmin -y
