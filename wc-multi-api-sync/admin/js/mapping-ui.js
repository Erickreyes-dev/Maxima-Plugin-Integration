jQuery(function ($) {
  var jsonPaths = [];

  function buildWooFieldOptions(selected) {
    var options = '<option value="">' + '--' + '</option>';
    wcMasAdmin.wooFields.forEach(function (field) {
      var isSelected = selected === field ? ' selected' : '';
      options += '<option value="' + field + '"' + isSelected + '>' + field + '</option>';
    });
    return options;
  }

  function buildJsonFieldOptions(selected) {
    var options = '<option value="">' + '--' + '</option>';
    jsonPaths.forEach(function (path) {
      var isSelected = selected === path ? ' selected' : '';
      options += '<option value="' + path + '"' + isSelected + '>' + path + '</option>';
    });
    return options;
  }

  function addMappingRow(wooField, jsonField) {
    var row = $('<tr>');
    row.append(
      '<td><select class="wc-mas-woo-field">' +
        buildWooFieldOptions(wooField) +
        '</select></td>'
    );
    row.append(
      '<td><select class="wc-mas-json-field">' +
        buildJsonFieldOptions(jsonField) +
        '</select></td>'
    );
    row.append(
      '<td><button class="button wc-mas-remove-row">Eliminar</button></td>'
    );
    $('#wc-mas-mapping-table tbody').append(row);
  }


  function initializeEditingMapping() {
    var form = $('#wc-mas-mapping-form');
    if (!form.length) {
      return;
    }

    var editingRaw = form.attr('data-editing-mapping') || '{}';
    var editing = {};
    try {
      editing = JSON.parse(editingRaw);
    } catch (error) {
      editing = {};
    }

    if (editing && Object.keys(editing).length) {
      Object.keys(editing).forEach(function (wooField) {
        addMappingRow(wooField, editing[wooField]);
      });
      return;
    }

    if ($('#wc-mas-mapping-table tbody tr').length === 0) {
      addMappingRow('', '');
    }
  }

  function buildMappingJson() {
    var mapping = {};
    $('#wc-mas-mapping-table tbody tr').each(function () {
      var wooField = $(this).find('.wc-mas-woo-field').val();
      var jsonField = $(this).find('.wc-mas-json-field').val();
      if (wooField && jsonField) {
        mapping[wooField] = jsonField;
      }
    });
    return mapping;
  }

  $('#wc-mas-add-mapping-row').on('click', function (event) {
    event.preventDefault();
    addMappingRow('', '');
  });

  $('#wc-mas-mapping-table').on('click', '.wc-mas-remove-row', function (event) {
    event.preventDefault();
    $(this).closest('tr').remove();
  });

  $('#wc-mas-detect-fields').on('click', function (event) {
    event.preventDefault();
    var providerId = $(this).data('provider');
    $('#wc-mas-json-paths').empty();

    $.post(wcMasAdmin.ajaxUrl, {
      action: 'wc_mas_get_json_paths',
      nonce: wcMasAdmin.nonce,
      provider_id: providerId,
    }).done(function (response) {
      if (response.success) {
        jsonPaths = response.data.paths || [];
        $('#wc-mas-json-paths').text(jsonPaths.join(', '));
        $('#wc-mas-sample-payload').val(response.data.sample || '');
        $('#wc-mas-mapping-table tbody').find('.wc-mas-json-field').each(function () {
          var selected = $(this).val();
          $(this).html(buildJsonFieldOptions(selected));
        });
        if ($('#wc-mas-mapping-table tbody tr').length === 0) {
          addMappingRow('', '');
        }
      } else {
        $('#wc-mas-json-paths').text(response.data.message || 'Error');
      }
    });
  });

  $('#wc-mas-preview-mapping').on('click', function (event) {
    event.preventDefault();
    var payload = $('#wc-mas-sample-payload').val();
    var mapping = JSON.stringify(buildMappingJson());
    $('#wc-mas-preview-output').text('');

    $.post(wcMasAdmin.ajaxUrl, {
      action: 'wc_mas_preview_mapping',
      nonce: wcMasAdmin.nonce,
      payload: payload,
      mapping: mapping,
    }).done(function (response) {
      if (response.success) {
        $('#wc-mas-preview-output').text(JSON.stringify(response.data.mapped, null, 2));
      } else {
        $('#wc-mas-preview-output').text(response.data.message || 'Error');
      }
    });
  });

  $('#wc-mas-mapping-form').on('submit', function () {
    var mapping = buildMappingJson();
    $('#wc-mas-mapping-json').val(JSON.stringify(mapping));
  });

  initializeEditingMapping();

  $('.wc-mas-delete-mapping').on('click', function (event) {
    event.preventDefault();
    if (!window.confirm('¿Eliminar este mapeo?')) {
      return;
    }
    var button = $(this);
    var mappingId = button.data('mapping-id');

    $.post(wcMasAdmin.ajaxUrl, {
      action: 'wc_mas_delete_mapping',
      nonce: wcMasAdmin.nonce,
      mapping_id: mappingId,
    }).done(function (response) {
      if (response.success) {
        button.closest('tr').remove();
      } else {
        alert(response.data.message || 'Error');
      }
    });
  });
});
