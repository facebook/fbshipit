#!/bin/sh
set -ex
hhvm --version

apt-get update
apt-get install -y python-pip locales
pip install Mercurial

locale-gen en_US.UTF-8
export LC_ALL=en_US.UTF-8

curl https://getcomposer.org/installer | hhvm -d hhvm.jit=0 --php -- /dev/stdin --install-dir=/usr/local/bin --filename=composer

cd /var/source
hhvm -d hhvm.jit=0 /usr/local/bin/composer install

hh_server --check $(pwd)
hhvm -d hhvm.php7.all=0 -d hhvm.jit=0 vendor/bin/phpunit
hhvm -d hhvm.php7.all=1 -d hhvm.jit=0 vendor/bin/phpunit
hhvm -d hhvm.php7.all=1 -d hhvm.jit=1 vendor/bin/phpunit
