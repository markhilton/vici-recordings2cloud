# vici-recordings2cloud
This script moves ViciDial recordings into the Google Cloud Storage

## How to
1. Copy example config file.
```
cp vici-config.example.php vici-config.php
```
2. Edit config file to setup your Google Cloud Storage bucket
3. Set up a cron job
```
*/15 * * * *   export CLOUDSDK_PYTHON=/usr/local/bin/python2.7 && /usr/bin/php PATH/vici-recordings2cloud.php
```
