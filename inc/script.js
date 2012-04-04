$(document).ready(function() {
	var windowHeight = $(window).height();
	$('div#book_mid').height(windowHeight);
	
	$('div#left_col').height(windowHeight-20).jScrollPane();
	$('div#right_col').height(windowHeight-20).jScrollPane();
	
	$('.loader')
		.css('opacity', 0)
		.on('ajaxStart', function(e) {
			console.log('Start');
			$(this).css('opacity', 1);
		})
		.on('ajaxStop', function(e) {
			console.log('Stop');
			$(this).css('opacity', 0);
		});
	$('button#testing').on('click', function(e) {
		console.log('AJAX test');
		$.ajax({
			url: 'crud.php',
			type: 'POST',
			data: {
				grimoire: 'adfwersbaer',
				name: 'NULL',
				reference: '23434'
			},
			success: function(data, status, xhr) {
				console.log(data);
			}
		});
	});
});

$(window).resize(function() {
	var windowHeight = $(window).height();
	$('div#book_mid').height(windowHeight);
	
	$('div#left_col').height(windowHeight-20).jScrollPane();
	$('div#right_col').height(windowHeight-20).jScrollPane();
});