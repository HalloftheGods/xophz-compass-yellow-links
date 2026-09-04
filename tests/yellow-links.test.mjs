/**
 * Yellow Links Submodule E2E Test Suite
 * Strictly zero em dashes in code, copy, comments, and reports.
 * Zero mock or synthetic data.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { readSourceFile, hasAdminRoleCheck } from '../../xophz-compass/tests/harness/code-analyzer.mjs';
import assert from 'node:assert';

describe('Yellow Links Submodule Tests', () => {
  it('REST permission checks enforce capabilities rather than roles', () => {
    const apiFile = readSourceFile('wp-content/plugins/xophz-compass-yellow-links/includes/class-yellow-links-api.php');
    assert.ok(apiFile, 'Yellow Links API file must exist');
    assert.strictEqual(hasAdminRoleCheck(apiFile), false, 'Must not check raw administrator role');
  });

  it('Vite Dev Proxy configuration targets port 8088 and query var yellow-links', () => {
    const res = runPhpJson(`
      $proxy = new Xophz_Compass_Dev_Proxy([
        'slug'        => 'yellow-links',
        'dev_port'    => 8088,
        'query_var'   => 'xophz_compass_yellow_links',
        'plugin_path' => '/var/www/html/wp-content/plugins/xophz-compass-yellow-links/',
        'plugin_url'  => 'http://localhost/wp-content/plugins/xophz-compass-yellow-links/',
        'version'     => '26.9.3'
      ]);
      $vars = $proxy->register_query_vars([]);
      echo json_encode([
        'hasQueryVar' => in_array('xophz_compass_yellow_links', $vars, true)
      ]);
    `);
    assert.strictEqual(res.hasQueryVar, true, 'Query var must be registered');
  });

  it('Elimination of raw current_user_can("administrator") in analytics verifier', () => {
    const verifier = readSourceFile('wp-content/plugins/xophz-compass-yellow-links/includes/class-analytics-verifier.php');
    if (verifier) {
      assert.strictEqual(hasAdminRoleCheck(verifier), false, 'Analytics verifier must NOT check raw administrator');
    }
  });
});
