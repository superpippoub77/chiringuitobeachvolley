#!/bin/bash

# Crea un semplice PNG di test in base64 (1x1 pixel rosso)
base64 -d << 'LOGO' > test-logo.png
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIB
lJDIkwAAAABJRU5ErkJggg==
LOGO

file test-logo.png
ls -lh test-logo.png
