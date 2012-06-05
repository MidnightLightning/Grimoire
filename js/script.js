var $curGrim = null;
$(document).ready(function() {
	// Save data to the "grim_display" wrapper object
	$curGrim = $('div#grim_display'); // Attach to Grimoire fields wrapper
	$curGrim.data('slots', []); // Start with empty slots array
	$curGrim.data('title', null);
	$curGrim.data('id', grim_id); // Defined by PHP
	
	// Initialize
	var $page_loader = $('p#page_loading').data('loading', true);
	if ($curGrim.data('id') !== false) {
		// Linked to an online ID; attempt to look it up.
		$.ajax({
			url: 'api/grimoire/'+$curGrim.data('id'),
			type: 'GET',
			dataType: 'json',
			success: function(rs, status, xhr) {
				$curGrim.data('title', rs.data.name);
				$('#grim_title').html(rs.data.name).removeClass('default');
				console.log(rs);
				$page_loader.css('opacity', 0);
				$curGrim.show();
			},
			error: function(xhr, status, err) {
				if (err == "Not Found") {
					// Bad ID
					var pieces = window.location.href.split('/');
					pieces.pop(); // Remove the ID number
					pieces[pieces.length-1] = 'app';
					window.location.href = pieces.join('/');
				}
			}
		});
	} else {
		restoreLocal(); // Attempt to restore any saved slots
		$page_loader.css('opacity', 0);
		$curGrim.show();
		saveRemote();
	}
	
	
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
	curSlots.push(slot); // Add new slot
	
	$li.attr('id', 'slot-'+(curSlots.length-1));
	$li.data('slot_number', (curSlots.length-1));
	$li.append('<span class="slot_name">'+slot.name+'</span>');
	$curGrim.data('slots', curSlots); // Save slot list
	
	$('ul#grim_slots').append($li);
	return true;
}

function save() {
	// Save to the server, if online
	saveRemote();
	// Save locally, if possible
	saveLocal();
}

function saveRemote() {
	console.log('Saving remote...');
	if (!isOnline()) return false;
	console.log('Online');
	if ($curGrim.data('id') == false) {
		// We have no ID yet; do a create call
		console.log('Creating Grimoire');
		$.ajax({
			url: 'api/grimoire/',
			type: 'POST',
			dataType: 'json',
			timeout: 10000,
			cache: false,
			data: {
				name: $curGrim.data('title'),
			},
			success: function(data, status, xhr) {
				$curGrim.data('id', data.public_key+data.admin_key); // Record own ID
				saveRemote(); // Call self again to save slots
			},
			error: function(xhr, status, err) {
				console.log(status, err);
			}
		});
	} else {
		// We do have an ID; save the slots
		var slots = $curGrim.data('slots');
		if ($curGrim.data('rowSaveCursor') == undefined) {
			// Set up save
			$curGrim.data('rowSaveCursor', slots.length-1);
			saveRemote(); // Call self again to start the save process
		} else {
			// Save that row
			var cursor = $curGrim.data('rowSaveCursor');
			var row = slots[cursor];
			console.log(JSON.stringify(row));
			$.ajax({
				url: 'api/row/',
				type: 'POST',
				dataType: 'json',
				timeout: 10000,
				cache: false,
				async: false, // Do the saves synchronously
				data: {
					gid: $curGrim.data('id'),
					order: cursor,
					data: JSON.stringify(row),
				},
				success: function(data, status, xhr) {
					if (cursor == 0) {
						// That was the last row
						$curGrim.removeData('rowSaveCursor');
						
						// Check URL for ID
						var parts = window.location.pathname.split('/');
						if (parts[parts.length-1] == 'app') {
							// Redirect
							parts[parts.length-1] = 'g';
							parts.push($curGrim.data('id'));
							//window.location.replace(window.location.protocol + '//' + window.location.host + parts.join('/'));
						}
					} else {
						$curGrim.data('rowSaveCursor', cursor-1); // Decrement cursor
						saveRemote(); // Save the next row
					}
				},
				error: function(xhr, status, err) {
					console.log(status, err);
				}
			});
		}
	}
}

function saveLocal() {
	if (!Modernizr.localstorage) return false;
	localStorage.setItem('grimoire.slots', JSON.stringify($curGrim.data('slots')));
	localStorage.setItem('grimoire.title', $curGrim.data('title'));
	localStorage.setItem('grimoire.id', $curGrim.data('id'));
}

function restoreLocal() {
	if (!Modernizr.localstorage) return false;
	var title = localStorage.getItem('grimoire.title');
	if (title != null) {
		$('#grim_title').html(title).removeClass('default');
		$curGrim.data('title', title);
	}
	
	var data = localStorage.getItem('grimoire.slots');
	if (data != null) {
		data = JSON.parse(data);
		for (i in data) {
			addSlot(data[i].name);
		}
	}
	
	var id = localStorage.getItem('grimoire.id');
	if (id != null) {
		$curGrim.data('id', id);
	}
	return true;
}

function isOnline() {
	if (typeof window.navigator.onLine == 'undefined') {
		$.ajax({
			async: false,
			url: '/',
			type: 'HEAD',
			success: function(data, status, xhr) {
				return true;
			},
			error: function(xhr, status, err) {
				return false;
			}
		})
	} else {
		return window.navigator.onLine;
	}
}
/*
if (Modernizr.localstorage) {
	// Save as a recent visit
	var recent = JSON.parse(localStorage.getItem('grimoire.recent'));
	recent.unshift($curGrim.data('id')); // Add this ID to the beginning of the list
	localStorage.setItem('grimoire.recent', JSON.stringify(recent));
}
*/