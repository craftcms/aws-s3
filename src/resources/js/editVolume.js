$(document).ready(function() {
  const $s3AccessKeyIdInput = $('.s3-key-id');
  const $s3SecretAccessKeyInput = $('.s3-secret-key');
  const $s3BucketSelect = $('.s3-bucket-select > select');
  const $s3RefreshBucketsBtn = $('.s3-refresh-buckets');
  const $s3RefreshBucketsSpinner = $s3RefreshBucketsBtn.parent().next().children();
  const $s3Region = $('.s3-region');
  const $manualBucket = $('.s3-manualBucket');
  const $manualRegion = $('.s3-manualRegion');
  const $fsUrl = $('.fs-url');
  const $hasUrls = $('input[name=hasUrls]');
  let refreshingS3Buckets = false;

  $s3RefreshBucketsBtn.click(function() {
    if ($s3RefreshBucketsBtn.hasClass('disabled')) {
      return;
    }

    $s3RefreshBucketsBtn.addClass('disabled');
    $s3RefreshBucketsSpinner.removeClass('hidden');

    const data = {
      keyId: $s3AccessKeyIdInput.val(),
      secret: $s3SecretAccessKeyInput.val()
    };

    Craft.sendActionRequest('POST', 'aws-s3/buckets/load-bucket-data', {data})
      .then(({data}) => {
        if (!data.buckets.length) {
          return;
        }
        //
        const currentBucket = $s3BucketSelect.val();
        let currentBucketStillExists = false;

        refreshingS3Buckets = true;

        $s3BucketSelect.prop('readonly', false).empty();

        for (let i = 0; i < length; i++) {
          if (data.buckets[i].bucket == currentBucket) {
            currentBucketStillExists = true;
          }

          $s3BucketSelect.append('<option value="' + data.buckets[i].bucket + '" data-url-prefix="' + data.buckets[i].urlPrefix + '" data-region="' + data.buckets[i].region + '">' + data.buckets[i].bucket + '</option>');
        }

        if (currentBucketStillExists) {
          $s3BucketSelect.val(currentBucket);
        }

        refreshingS3Buckets = false;

        if (!currentBucketStillExists) {
          $s3BucketSelect.trigger('change');
        }
      })
      .catch(({response}) => {
        alert(response.data.message);
      })
      .finally(() => {
        $s3RefreshBucketsBtn.removeClass('disabled');
        $s3RefreshBucketsSpinner.addClass('hidden');
      });
  });

  $s3BucketSelect.change(function() {
    if (refreshingS3Buckets) {
      return;
    }

    const $selectedOption = $s3BucketSelect.children('option:selected');

    $fsUrl.val($selectedOption.data('url-prefix'));
    $s3Region.val($selectedOption.data('region'));
  });

  const s3ChangeExpiryValue = function() {
    const parent = $(this).parents('.field');
    const amount = parent.find('.s3-expires-amount').val();
    const period = parent.find('.s3-expires-period select').val();

    const combinedValue = (parseInt(amount, 10) === 0 || period.length === 0) ? '' : amount + ' ' + period;

    parent.find('[type=hidden]').val(combinedValue);
  };

  $('.s3-expires-amount').keyup(s3ChangeExpiryValue).change(s3ChangeExpiryValue);
  $('.s3-expires-period select').change(s3ChangeExpiryValue);


  const maybeUpdateUrl = function() {
    if ($hasUrls.val() && $manualBucket.val().length && $manualRegion.val().length) {
      $fsUrl.val('https://s3.' + $manualRegion.val() + '.amazonaws.com/' + $manualBucket.val() + '/');
    }
  };

  $manualRegion.keyup(maybeUpdateUrl);
  $manualBucket.keyup(maybeUpdateUrl);
});
