$(document).ready(function () {
    const $s3AccessKeyIdInput = $('.s3-key-id');
    const $s3SecretAccessKeyInput = $('.s3-secret-key');
    const $s3BucketSelect = $('.s3-bucket-select > select');
    const $s3RefreshBucketsBtn = $('.s3-refresh-buckets');
    const $s3Region = $('.s3-region');
    const $manualBucket = $('.s3-manualBucket');
    const $manualRegion = $('.s3-manualRegion');
    const $fsUrl = $('.fs-url');
    const $hasUrls = $('input[name=hasUrls]');
    let refreshingS3Buckets = false;

    $s3RefreshBucketsBtn.click(function () {
        if ($s3RefreshBucketsBtn.attr('disabled')) {
            return;
        }

        $s3RefreshBucketsBtn.attr('disabled', true);
        $s3RefreshBucketsBtn.addClass(['loading', 'disabled']);

        const data = {
            keyId: $s3AccessKeyIdInput.val(),
            secret: $s3SecretAccessKeyInput.val(),
        };

        Craft.sendActionRequest('POST', 'aws-s3/buckets/load-bucket-data', {data})
            .then(({ data }) => {
                if (!data.buckets.length) {
                    return;
                }

                // Capture current value:
                const currentBucket = $s3BucketSelect.val();
                let currentBucketStillExists = false;

                refreshingS3Buckets = true;

                // Remove existing <option>s:
                $s3BucketSelect.prop('readonly', false).empty();

                for (let i = 0; i < data.buckets.length; i++) {
                    let bucket = data.buckets[i];

                    // Set a flag so we can restore the selection:
                    if (bucket.name === currentBucket) {
                        currentBucketStillExists = true;
                    }

                    // Build a new <option>:
                    const $option = document.createElement('option');
                    $option.value = bucket.name;
                    $option.dataset.urlPrefix = bucket.urlPrefix;
                    $option.dataset.region = bucket.region;
                    $option.textContent = bucket.name;

                    $s3BucketSelect.append($option);
                }

                // Restore previous selection:
                if (currentBucketStillExists) {
                    $s3BucketSelect.val(currentBucket);
                }

                // Fire a change event so other listeners can pick up the new value:
                if (!currentBucketStillExists) {
                    $s3BucketSelect.trigger('change');
                }
            })
            .catch(({ response }) => {
                Craft.cp.displayError(response.data.message);
            })
            .finally(() => {
                refreshingS3Buckets = false;

                $s3RefreshBucketsBtn.attr('disabled', false);
                $s3RefreshBucketsBtn.removeClass(['loading', 'disabled']);
            });
    });

    $s3BucketSelect.change(function () {
        if (refreshingS3Buckets) {
            return;
        }

        const $selectedOption = $s3BucketSelect.children('option:selected');

        $fsUrl.val($selectedOption.data('url-prefix'));
        $s3Region.val($selectedOption.data('region'));
    });

    const s3ChangeExpiryValue = function () {
        const parent = $(this).parents('.field');
        const amount = parent.find('.s3-expires-amount').val();
        const period = parent.find('.s3-expires-period select').val();

        const combinedValue =
            parseInt(amount, 10) === 0 || period.length === 0
                ? ''
                : amount + ' ' + period;

        parent.find('[type=hidden]').val(combinedValue);
    };

    $('.s3-expires-amount')
        .keyup(s3ChangeExpiryValue)
        .change(s3ChangeExpiryValue);
    $('.s3-expires-period select').change(s3ChangeExpiryValue);

    const maybeUpdateUrl = function () {
        if (
            $hasUrls.val() &&
            $manualBucket.val().length &&
            $manualRegion.val().length
        ) {
            $fsUrl.val(
                'https://s3.' +
                $manualRegion.val() +
                '.amazonaws.com/' +
                $manualBucket.val() +
                '/'
            );
        }
    };

    $manualRegion.keyup(maybeUpdateUrl);
    $manualBucket.keyup(maybeUpdateUrl);
});
