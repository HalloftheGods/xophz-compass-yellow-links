/**
 * Yellow Links Submodule Test Runner
 * Strictly zero em dashes in code, copy, comments, and reports.
 * Zero mock or synthetic data.
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './yellow-links.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  console.error(`Yellow Links Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`Yellow Links Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
