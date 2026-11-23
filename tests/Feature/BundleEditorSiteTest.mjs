import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

test('the component selector and row request retain the editor site', async () => {
  let selectorSettings;
  let requestData;
  const Craft = {
    createElementSelectorModal(type, settings) {
      selectorSettings = settings;
      return {};
    },
    sendActionRequest(method, action, request) {
      requestData = request.data;
      return Promise.resolve({data: {html: ''}});
    },
  };
  const context = vm.createContext({Craft, Garnish: {Base: {extend: definition => definition}}});
  vm.runInContext(readFileSync(new URL('../../src/assets/dist/js/bundle-editor.js', import.meta.url), 'utf8'), context);
  const input = {
    settings: {siteId: 2, typeId: 3, sources: '*', namespace: 'slideout'},
    _currentProductIds: () => [],
  };
  Craft.BundleBuilder.ComponentsInput.openPicker.call(input);
  assert.equal(selectorSettings.criteria.siteId, 2);
  assert.equal(selectorSettings.siteIds[0], 2);
  assert.equal(selectorSettings.showSiteMenu, false);
  await Craft.BundleBuilder.ComponentsInput._fetchRows.call(input, 0, [10], 1);
  assert.equal(requestData.siteId, 2);
  assert.equal(requestData.namespace, 'slideout');
});
