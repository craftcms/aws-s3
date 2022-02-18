$(document).ready(function() {

	var $s3AccessKeyIdInput = $('.s3-key-id'),
		$s3SecretAccessKeyInput = $('.s3-secret-key'),
		$s3BucketSelect = $('.s3-bucket-select > select'),
		$s3RefreshBucketsBtn = $('.s3-refresh-buckets'),
		$s3RefreshBucketsSpinner = $s3RefreshBucketsBtn.parent().next().children(),
		$s3Region = $('.s3-region'),
		$manualBucket = $('.s3-manualBucket'),
		$manualRegion = $('.s3-manualRegion'),
		$volumeUrl = $('.volume-url'),
		$hasUrls = $('input[name=hasUrls]'),
		refreshingS3Buckets = false;

	$s3RefreshBucketsBtn.click(function()
	{
		if ($s3RefreshBucketsBtn.hasClass('disabled'))
		{
			return;
		}

		$s3RefreshBucketsBtn.addClass('disabled');
		$s3RefreshBucketsSpinner.removeClass('hidden');

		var data = {
			keyId:  $s3AccessKeyIdInput.val(),
			secret: $s3SecretAccessKeyInput.val()
		};

		const onResponse = () => {
			$s3RefreshBucketsBtn.removeClass('disabled');
			$s3RefreshBucketsSpinner.addClass('hidden');
		};

		Craft.sendActionRequest('POST', 'aws-s3', {data})
			.then((response) => {
				onResponse();
				const {data} = response;

				if (!data.length) {
					return;
				}
				//
				var currentBucket = $s3BucketSelect.val(),
					currentBucketStillExists = false;

				refreshingS3Buckets = true;

				$s3BucketSelect.prop('readonly', false).empty();

				for (var i = 0; i < length; i++)
				{
					if (data[i].bucket == currentBucket)
					{
						currentBucketStillExists = true;
					}

					$s3BucketSelect.append('<option value="'+data[i].bucket+'" data-url-prefix="'+data[i].urlPrefix+'" data-region="'+data[i].region+'">'+data[i].bucket+'</option>');
				}

				if (currentBucketStillExists)
				{
					$s3BucketSelect.val(currentBucket);
				}

				refreshingS3Buckets = false;

				if (!currentBucketStillExists)
				{
					$s3BucketSelect.trigger('change');
				}
			})
			.catch(({response}) => {
				onResponse();
				alert(data.message);
			});
	});

	$s3BucketSelect.change(function()
	{
		if (refreshingS3Buckets)
		{
			return;
		}

		var $selectedOption = $s3BucketSelect.children('option:selected');

		$('.volume-url').val($selectedOption.data('url-prefix'));
		$s3Region.val($selectedOption.data('region'));
	});

	var s3ChangeExpiryValue = function ()
	{
		var parent = $(this).parents('.field'),
			amount = parent.find('.s3-expires-amount').val(),
			period = parent.find('.s3-expires-period select').val();

		var combinedValue = (parseInt(amount, 10) === 0 || period.length === 0) ? '' : amount + ' ' + period;

		parent.find('[type=hidden]').val(combinedValue);
	};

	$('.s3-expires-amount').keyup(s3ChangeExpiryValue).change(s3ChangeExpiryValue);
	$('.s3-expires-period select').change(s3ChangeExpiryValue);


	var maybeUpdateUrl = function () {
		if ($hasUrls.val() && $manualBucket.val().length && $manualRegion.val().length) {
			$volumeUrl.val('https://s3.' + $manualRegion.val() + '.amazonaws.com/' + $manualBucket.val() + '/');
		}
	};

	$manualRegion.keyup(maybeUpdateUrl);
	$manualBucket.keyup(maybeUpdateUrl);
});
