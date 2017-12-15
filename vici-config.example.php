<?php

define('GCS_BUCKET',                                    ''); // name of Google Cloud Storage bucket
define('MP3_PATH',   '/var/spool/asterisk/monitorDONE/MP3'); // source path for recordings location
define('ORIG_PATH', '/var/spool/asterisk/monitorDONE/ORIG'); // source path for uncompressed recordings location
define('GSUTIL',       '/root/google-cloud-sdk/bin/gsutil'); // gsutil location path
define('DELETE_FILE_LESS_THAN',                         30); // remove recodings lasting less than 30 seconds
