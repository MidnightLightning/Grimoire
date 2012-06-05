var $curGrim = null;
$(document).ready(function() {
	// Save data to the "grim_display" wrapper object
	$curGrim = $('div#grim_display');
	$curGrim.data('slots', []); // Start with empty slots array
	restoreLocal(); // Attempt to restore any saved slots
	
		});
	
	// New slot save action
	$('input#new_slot_text').on('keydown', function(e) {
		if (e.which == 9 || e.which == 13) {
			// Tab or enter pressed
			e.preventDefault(); // Stay in this field
			var $new = $(this);
			addSlot($new.val());
			
			saveLocal();
			$new.val(''); // Clear input
		}
		//console.log(e.which);
	});
	
	// Existing slot edit action
	$(document).on('dblclick', 'ul#grim_slots li span.slot_name', function(e) {
		e.preventDefault();
		var $li = $(this).parent();
		var text = $li.find('span.slot_name').text();
		var $input = $('<input type="text" class="slot_update" />');
		$input.val(text);
		$li.html('').append($input);
		$input.select();
	}).on('keydown', 'ul#grim_slots li input.slot_update', function(e) {
		if (e.which == 9 || e.which == 13) {
			e.preventDefault();
			var $li = $(this).parent();
			var slots = $curGrim.data('slots');
			var slot = slots[$li.data('slot_number')];
			slot.name = $li.find('input.slot_update').val();
			
			$curGrim.data('slots', slots); // Save slot list
			saveLocal();
			$li.html('').append('<span class="slot_name">'+slot.name+'</span>');
		}
	});
	
	// Title edit action
	$('#grim_title').on('dblclick', function(e) {
		var $title = $(this);
		var $input = $('<input type="text" id="title_update" />');
		var text = $title.text();
		if (text == "New Grimoire") text = '';
		$input.val(text);
		$title.html('').append($input);
		$input.select();
	});
	
	$(document).on('keydown', 'input#title_update', function(e) {
		if (e.which == 9 || e.which == 13) {
			e.preventDefault();
			var $title = $(this).parent();
			var newTitle = $title.find('input#title_update').val();
			if (newTitle == '') {
				// Set to default
				newTitle = "New Grimoire";
				$title.addClass('default');
				$curGrim.data('title', null);
			} else {
				$title.removeClass('default');
				$curGrim.data('title', newTitle);
			}

			saveLocal();
			$title.html(newTitle);
		}
	});
});

function addSlot(name) {
	var $li = $('<li />'); // Build a new slot item
	var slot = {'name':name };
	var curSlots = $curGrim.data('slots');
	curSlots.unshift(slot); // Add new slot
	
	$li.attr('id', 'slot-'+(curSlots.length-1));
	$li.data('slot_number', (curSlots.length-1));
	$li.append('<span class="slot_name">'+slot.name+'</span>');
	$curGrim.data('slots', curSlots); // Save slot list
	
	$('ul#grim_slots').append($li);
	return true;
}


function saveLocal() {
	if (!Modernizr.localstorage) return false;
	localStorage.setItem('grimoire.slots', JSON.stringify($curGrim.data('slots')));
	localStorage.setItem('grimoire.title', $curGrim.data('title'));
}

function restoreLocal() {
	if (!Modernizr.localstorage) return false;
	var title = localStorage.getItem('grimoire.title');
	if (title != null) $('#grim_title').html(title).removeClass('default');
	
	var data = localStorage.getItem('grimoire.slots');
	if (data != null) {
		data = JSON.parse(data);
		for (i in data) {
			addSlot(data[i].name);
		}
	}
	return true;
}