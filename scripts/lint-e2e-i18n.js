#!/usr/bin/env node
'use strict';
// Thin wrapper: farm-shared locale-fragile selector lint.
// Implementation lives in apps/_shared/e2e/lint-e2e-i18n.js.
const path = require('path');
const shared = path.resolve(__dirname, '..', '..', '_shared', 'e2e', 'lint-e2e-i18n.js');
process.argv[2] = path.resolve(__dirname, '..');
require(shared);
