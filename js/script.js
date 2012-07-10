var GrimoireRow = Backbone.Model.extend({
	urlRoot: 'api/row'
});
var GrimoireRows = Backbone.Collection.extend({
	model: GrimoireRow
});
var Grimoire = Backbone.Model.extend({
	defaults: {
		'name':'New Grimoire'
	},
	idAttribute: "public_key",
	urlRoot: 'api/grimoire',
	url: function() {
		return this.urlRoot+'/'+this.myKey();
	},
	parse: function(response) {
		var response = _.clone(response);
		response.rows = undefined;
		return response;
	},
	myKey: function() {
		var key = this.get('public_key');
		if (typeof this.get('admin_key') != 'undefined') {
			key += this.get('admin_key');
		}
		return key;
	}
});

var GrimoireTitle = Backbone.View.extend({
	render: function() {
		var name = this.model.get('name');
		if (name != '' || name == this.model.defaults.name) {
			this.$el.html('<span class="name">'+this.model.get('name')+'</span>').removeClass('default');
		} else {
			this.$el.html('<span class="name">'+this.model.defaults.name+'</span>').addClass('default');
		}
		return this; // Chain
	},
	events: {
		'dblclick .name': 'edit',
		'blur .title_update': 'endEdit',
		'keydown .title_update': 'keyEvent'
	},
	initialize: function() {
		this.model.on('change:name', this.render, this);
	},
	edit: function() {
		if (!cur_grim.writeAccess) return false;
		$input = this.make('input', {'type':'text', 'class':'title_update', 'value':this.model.get('name')});
		this.$el.html($input);
		$input.select();
	},
	endEdit: function(e) {
		this.model.set({'name': this.$el.find('input.title_update').val()}); // Save new name
		this.render(); // Reset to default view
	},
	keyEvent: function(e) {
		if (e.which == 13) {
			// Enter key was pressed
			this.endEdit();
		}
	}
});

var GrimoireRowsView = Backbone.View.extend({
	tagName: 'ul',
	initialize: function() {
		this.model.on('reset', this.render, this);
	},
	render: function() {
		this.$el.html(); // Clear existing
		this.model.each(function (e, i) {
			this.$el.append(new GrimoireRowView({model:e}).render().el);
			e.on('change', function(model) { model.save(); });
		}, this);
		return this; // Chain
	}
});
var GrimoireRowView = Backbone.View.extend({
	tagName: 'li',
	render: function() {
		this.$el.html('<span class="name">'+this.model.get('name')+'</span>');
		return this; // Chain
	},
	events: {
		'dblclick .name': 'edit',
		'blur .slot_update': 'endEdit',
		'keydown .slot_update': 'keyEvent'
	},
	edit: function() {
		if (!cur_grim.writeAccess) return false;
		$input = this.make('input', {'type':'text', 'class':'slot_update', 'value':this.model.get('name')});
		this.$el.html($input);
		$input.select();
	},
	endEdit: function(e) {
		this.model.set({'name': this.$el.find('input.slot_update').val()}); // Save new name
		this.render(); // Reset to default view
	},
	keyEvent: function(e) {
		if (e.which == 13) {
			// Enter key was pressed
			this.endEdit();
		}
	}
})

$(document).ready(function() {
	var $page_loader = $('p#page_loading');
	var $curGrim = $('div#grim_display'); // Attach to Grimoire fields wrapper
	window.cur_grim = {}; // Standard object for holding data
	
	if (window.location.hash) {
		// See if the hash is a valid grimoire
		cur_grim.model = new Grimoire({
			public_key: window.location.hash.substring(1)
		});
		cur_grim.model.on('all', function(name) { console.log('cur_grim: ', name); });
		cur_grim.rows = new GrimoireRows();
		cur_grim.rows.on('all', function(name) { console.log('rows: ', name); });

		new GrimoireTitle({
			model: cur_grim.model,
			el: $curGrim.find('#grim_title').get(0)
		});
		new GrimoireRowsView({
			model: cur_grim.rows,
			el: $curGrim.find('ul#grim_slots').get(0)
		})
		
		var $xhr = cur_grim.model.fetch({
			success: function(model, response) {
				// This is a valid Grimoire
				cur_grim.rows.reset(response.rows); // Save the row collection
				cur_grim.rows.each(function(e, i) {
					e.set({'order':i, 'gid':cur_grim.model.myKey()}, {silent:true});
				});
				
				$page_loader.hide();
				$curGrim.show();
				
				cur_grim.model.on('change:name', function(model, newValue) {
					model.save();
				});
			}
		});
		parseWriteHeader($xhr);
	} else {
		// Work on a local Grimoire
		$page_loader.hide();
		$curGrim.show();
	}
});

function parseWriteHeader($xhr) {
	if ($xhr.state() == 'pending') {
		setTimeout(function() { parseWriteHeader($xhr); }, 200); // Wait until complete
	} else {
		cur_grim.writeAccess = ($xhr.getResponseHeader('GRIMOIRE-WRITE-ACCESS') == 'true')? true : false;
	}
}
/*	
	// Save data to the "grim_display" wrapper object
	$curGrim = $('div#grim_display'); // Attach to Grimoire fields wrapper
	$curGrim.data('slots', []); // Start with empty slots array
	$curGrim.data('title', null);
	$curGrim.data('id', grim_id); // Defined by PHP
	
	// Initialize
	var $page_loader = $('p#page_loading').data('loading', true);
	restoreLocal(); // Attempt to restore any saved slots
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
				$page_loader.hide();
				$curGrim.show();
			},
			error: function(xhr, status, err) {
				if (err == "Not Found") {
					// Bad ID
					var pieces = window.location.href.split('/');
					if (pieces[pieces.length-1] != 'app') {
						pieces.pop(); // Remove the ID number
						pieces[pieces.length-1] = 'app';
						window.location.href = pieces.join('/');
					}
				}
			}
		});
	} else {
		$page_loader.hide();
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
	}).on('keydown blur', 'ul#grim_slots li input.slot_update', function(e) {
		if (e.which == 9 || e.which == 13 || e.type == 'focusout') {
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
	
	$(document).on('keydown blur', 'input#title_update', function(e) {
		if (e.which == 9 || e.which == 13 || e.type == 'focusout') {
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
				saveLocal(); // Update local GID
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
*/
/*
if (Modernizr.localstorage) {
	// Save as a recent visit
	var recent = JSON.parse(localStorage.getItem('grimoire.recent'));
	recent.unshift($curGrim.data('id')); // Add this ID to the beginning of the list
	localStorage.setItem('grimoire.recent', JSON.stringify(recent));
}
*/