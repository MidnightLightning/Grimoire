$(document).ready(function() {
	var windowHeight = $(window).height();
	$('div#book_mid').height(windowHeight);
	
	$('div#left_col').height(windowHeight-20).jScrollPane();
	$('div#right_col').height(windowHeight-20).jScrollPane();
});

$(window).resize(function() {
	var windowHeight = $(window).height();
	$('div#book_mid').height(windowHeight);
	
	$('div#left_col').height(windowHeight-20).jScrollPane();
	$('div#right_col').height(windowHeight-20).jScrollPane();
});