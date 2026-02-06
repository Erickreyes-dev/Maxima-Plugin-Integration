jQuery(function ($) {
  $('#wc-mas-test-endpoint').on('click', function (event) {
    event.preventDefault();
    var providerId = $(this).data('provider');
    $('#wc-mas-preview-output').text('');

    $.post(wcMasAdmin.ajaxUrl, {
      action: 'wc_mas_test_endpoint',
      nonce: wcMasAdmin.nonce,
      provider_id: providerId,
    }).done(function (response) {
      if (response.success) {
        $('#wc-mas-sample-payload').val(response.data.body);
      } else {
        $('#wc-mas-preview-output').text(response.data.message || 'Error');
      }
    });
  });

  $('#wc-mas-preview-mapping').on('click', function (event) {
    event.preventDefault();
    var payload = $('#wc-mas-sample-payload').val();
    var mapping = $('#mapping_json').val();
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
});
