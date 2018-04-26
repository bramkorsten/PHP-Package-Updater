@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../odan/phinx-migrations-generator/bin/phinx-migrations
php "%BIN_TARGET%" %*
