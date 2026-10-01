@echo off
cd /d "%~dp0"
echo Starting Submittal Review demo...
start "" http://localhost:4817/
node server.mjs
