/* Author:

*/

$(document).ready(function() {
	$('div#book_mid').height($(window).height());
});

$(window).resize(function() {
	$('div#book_mid').height($(window).height());
});