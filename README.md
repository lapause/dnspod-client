# DnsPod.com client

### Preliminary note

[DnsPod](https://www.dnspod.com) is a DNS provider that offers their free users quite a lot of features compared to other providers (unlimited domains, unlimited records, low TTLs, area-based records, round robin, wildcards... - [see full list](https://www.dnspod.com/products)).

They do not provide a subdomain service (e.g. foo.dnspod.com) like [no-ip](http://www.noip.com) though, you must have your own domain name.

Despite the fact clients are mentioned on their website, none is available to download, so... here is one.

### This client
It is coded in **PHP**, and can be used **from command-line or from PHP scripts** to perform periodic or on-demand updates of your DNS records.

[DnsPod](https://www.dnspod.com) appears in the list of available dynamic DNS providers in [Synology](http://www.synology.com/) NAS products, but the underlying implementation is missing. This client has also been developed to be **fully compatible with Synology DSM OS** and fill this gap (see configuration procedure below).

## Requirements

The client requires PHP 5 (regular and CLI version if you want to use it from command-line) and PHP cURL extension to be installed on the system.

## Usage

### From command-line

#### Arguments

- **username** : Your DnsPod login (email)
- **password** : Your DnsPod password
- **hostname** : The FQDN you want to update (e.g. foo.com or foo.bar.com)
- [ _ip_ ] : The IP address you want to assign to DNS record. If left empty, it will be grabbed automatically

```bash
$ php dnspod.php foo@bar.com mypassword foo.bar.com
```

> Alternatively, you can make the script executable directly. Just add the following shebang line at the beginning of the file before the PHP opening tag:

> _\#!/usr/bin/env php_

> And change the file rights so it can be executed and copy it in a handy place :

```bash
$ mv dnspod.php /bin/dnspod && chmod +x /bin/dnspod
$ dnspod foo@bar.com mypassword foo.bar.com
```

### From a PHP script

Include the script, directly or by binding **DnsPod** namespace to it via your favorite autoloader. You can then update your DNS in one line:

```php
require_once("dnspod.php");
DnsPod\DnsPod::update("foo@bar.com", "mypassword", "foo.bar.com");
```

## Features

### What it does

- It connects to your DnsPod account through the DnsPod API
- It grabs DNS records matching the FQDN you provided
- It skips all records whose type is not **A** or **CNAME**
- It removes matching records whose type is **CNAME**, as we will use **A** record
- It removes matching records whose area is different than 'default' (see below)
- This leaves us with the record we want: if it exists, its value is updated if necessary, and if the record was disabled it is automatically enabled
- If record didn't exist, it is automatically created

### What it does not

- It does not create the domain itself: it must already exist in your DnsPod account
- It does not handle MX records
- It does not handle multi-area records. DnsPod supports setting different records depending of the geographical zone or country of the end-user, but their API does not provide endpoint to grab useful info about them. As there is inconsistencies between area codes, values returned by the API and ISO 3166-1 codes, this client does not support multi-area record update.

### Customization

- If you want, you can hardcode username, password and hostname in the class variables. In that case, the script can be executed without arguments
- You can also change the IP grabber URL for your favorite one directly in the class vars
- By default, TTL of the DNS record is set to 300. You can change that by setting the _ttl_ class var to the value of your choice:

```php
$dnspod = DnsPod\DnsPod("foo@bar.com", "mypassword", "foo.bar.com");
$dnspod->ttl = 900;
$dnspod->update_record();
```

### Return codes

When executed from command line, messages are sent to _stderr_ and a code is returned to _stdout_. This code is based on Synology DDNS service specifications. Here are the possible values and their textual counterparts:

- _good_ : Update successfully
- _nochg_ : Update successfully but the IP address have not changed
- _nohost_ : The hostname specified does not exist in this user account
- _abuse_ [not handled] : The hostname specified is blocked for update abuse
- _notfqdn_ : The hostname specified is not a fully-qualified domain name
- _badauth_ : Authenticate failed
- _911_ : There is a problem or scheduled maintenance on provider side
- _badagent_ : The user agent sent bad request(like HTTP method/parameters is not permitted)
- _badresolv_ : Failed to connect to  because failed to resolve provider address
- _badconn_ : Failed to connect to provider because connection timeout
- _error_ : An error has occured [This one has been added to provide a catch-all error code]


## Integration with Synology DSM

> This procedure has been tested with Synology DSM 4.3

1. First, log in to your NAS with SSH as root.
2. Grab the script, add the shebang line and make it executable:

        $ wget -nv --no-check-certificate https://github.com/lapause/dnspod-client/raw/master/dnspod.php
        $ echo '#!/usr/bin/env php' | cat - dnspod.php > /sbin/dnspod
        $ chmod +x /sbin/dnspod
        $ rm dnspod.php

3. Update the entry for DnsPod in the DDNS configuration files:

        $ sed -i -re '/\[DNSPod\.com\]/{n;s@modulepath=.+@modulepath=/sbin/dnspod@}' /etc.defaults/ddns_provider.conf
        $ sed -i -re '/\[DNSPod\.com\]/{n;s@modulepath=.+@modulepath=/sbin/dnspod@}' /etc/ddns_provider.conf

4. You should be good to go. Connect to your NAS web interface, and go to **Control Panel > Network services > DDNS**, then enter your settings and test the connection.

## License

**This client is released under the MIT license.**

> Copyright (c) 2013 Pierre Guillaume

> Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

> The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

## Donations

This script has been useful to you?

> Feel free to donate BTC: 17zxz2u8aDgUD2YfcT7Vf5ms3UvqV29SUG
