var GrimoireRow = Backbone.Model.extend({
	urlRoot: 'api/row/',
	saveRow: function(options) {
		options || (options = {});
		var data = _.extend(this.toJSON(), {
			gid: cur_grim.model.myKey(),
			order: this.collection.indexOf(this)
		});
		this.save({}, _.extend({
			data: JSON.stringify(data),
			contentType: 'application/json'
		}, options));
	}
});
var GrimoireRows = Backbone.Collection.extend({
	model: GrimoireRow
});
var Grimoire = Backbone.Model.extend({
	defaults: {
		'name':'New Grimoire'
	},
	idAttribute: "public_key",
	urlRoot: 'api/grimoire/',
	url: function() {
		return (this.isNew())? this.urlRoot : this.urlRoot+this.myKey();
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

var GrimoireHeader = Backbone.View.extend({
	render: function() {
		var name = this.model.get('name');
		this.$el.html(''); // Clear existing
		
		if (cur_grim.writeAccess) this.$el.append('<div id="grim_link_icon" title="show links">#</div>');
		if (name != '' && name != this.model.defaults.name) {
			this.$el.append('<h1 id="grim_title"><span class="name">'+this.model.get('name')+'</span></h1>');
		} else {
			this.$el.append('<h1 id="grim_title" class="default"><span class="name">'+this.model.defaults.name+'</span></h1>');
		}
		
		if (cur_grim.writeAccess && !this.model.isNew()) {
			var base_url = window.location.protocol+'//'+window.location.host+window.location.pathname+'#';
			this.$el.append('<div id="grim_link_content"><dl><dt>Public (read-only) URL:</dt><dd>'+base_url+this.model.get('public_key')+'</dd><dt>Admin (write-access) URL:</dt><dd>'+base_url+this.model.get('public_key')+this.model.get('admin_key')+'</dd></dl></div>');
		}
		return this; // Chain
	},
	events: {
		'dblclick .name': 'edit',
		'blur .title_update': 'endEdit',
		'keydown .title_update': 'keyEvent'
	},
	initialize: function() {
		this.model.on('change:name change:public_key', this.render, this);
	},
	edit: function() {
		if (!cur_grim.writeAccess) return false;
		$input = this.make('input', {'type':'text', 'class':'title_update', 'value':this.model.get('name')});
		this.$el.find('h1').html($input).removeClass('default');
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
		this.model.on('reset remove', this.render, this);
		this.model.on('add', function(e, m) {
			var justAdded = m.last();
			this.$el.append(new GrimoireRowView({model: justAdded}).render().el);
			justAdded.saveRow({
				success: function(model) {
					model.on('change', function(model) { model.saveRow(); }); // Add this after the initial "saveRow()", to miss the "change:id" event
				}
			});
		}, this);
	},
	render: function() {
		this.$el.html(''); // Clear existing
		this.model.each(function (e, i) {
			this.$el.append(new GrimoireRowView({model:e}).render().el);
			e.on('change', function(model) { model.saveRow(); });
		}, this);
		this.$el.sortable({
			placeholder: "grim-sort-placeholder",
			update: _.bind(function(e, ui) {
				var collection = this.model;
				var newList = [];
				this.$el.children().each(function(i) {
					var id = $(this).data('id'); // Which row is this list item associated with?
					newList.push(collection.get(id));
				});
				collection.reset(newList, {silent: true}); // Create a new order; no need to trigger an event since we're already in order
				collection.each(function (e, i) {
					e.saveRow(); // Notice the new order
				}, collection);
			}, this)
		});
		return this; // Chain
	}
});
var GrimoireRowView = Backbone.View.extend({
	tagName: 'li',
	render: function() {
		this.$el.html('<span class="name">'+this.model.get('name')+'</span>');
		this.$el.data('id', this.model.get('id')); // Save this model's row, so the sortable event can find it
		return this; // Chain
	},
	initialize: function() {
		this.model.on('doEdit', this.edit, this);
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
		var newName = this.$el.find('input.slot_update').val();
		if (newName == '') {
			// Delete row
			this.model.destroy({
				data: JSON.stringify({gid: cur_grim.model.myKey()}), // Authenticate with Grimoire ID
				contentType: 'application/json' // Backbone doesn't set this explicitly, so defaults to 'application/x-www-form-urlencoded'
			});
		} else {
			// Save row
			this.model.set({'name': newName}); // Save new name
			this.render(); // Reset to default view
		}
	},
	keyEvent: function(e) {
		console.log('key:', e.which);
		switch(e.which) {
			case 13: // Enter
				this.endEdit();
				break;
			case 27: // Escape
				this.$el.find('input.slot_update').val(this.model.get('name')); // Need to reset, because a 'blur' event will be fired by re-rendering
				this.render();
				break;
			case 9: // Tab
				var myIndex = this.model.collection.indexOf(this.model);
				var nextModel = this.model.collection.at(e.shiftKey ? myIndex-1 : myIndex+1);
				if (nextModel != undefined) {
					e.preventDefault(); // Don't jump to next tab item
					nextModel.trigger('doEdit');
				}
				break;
		}
	}
})

$(document).ready(function() {
	var $page_loader = $('p#page_loading');
	var $curGrim = $('div#grim_display'); // Attach to Grimoire fields wrapper
	window.cur_grim = { // Standard object for holding data
		parseWriteHeader: function($xhr) {
			if ($xhr.state() == 'pending') {
				setTimeout(function() { cur_grim.parseWriteHeader($xhr); }, 200); // Wait until complete
			} else {
				this.setAccess(($xhr.getResponseHeader('GRIMOIRE-WRITE-ACCESS') == 'true')? true : false);
			}
		},
		setAccess: function(newValue) {
			var oldValue = (this.writeAccess)? true : false; // Clone
			this.writeAccess = newValue;
			if (oldValue != newValue) this.trigger('change:permission', newValue);
			this.trigger('sync:permission', newValue);
		},
		
		doHeartbeat: function() {
			if (!cur_grim.writeAccess) {
				// We're in read-only mode; just ovewrite the current
				cur_grim.model.fetch({
					success: function(model, response) {
						// This is a valid Grimoire
						if (JSON.stringify(response.rows) != JSON.stringify(cur_grim.rows)) {
							cur_grim.rows.reset(response.rows); // Save the row collection
						}
					}
				});

			}
		},
		
		saveRecent: function() {
			if (Modernizr.localstorage) {
				// Save as a recent visit
				var recent = JSON.parse(localStorage.getItem('grimoire.recent'));
				if (recent == null) recent = [];
				recent.unshift(this.model.get('public_key')); // Add this ID to the beginning of the list
				recent = _.uniq(recent);
				localStorage.setItem('grimoire.recent', JSON.stringify(recent));
			}
		},

		addSlot: function(name) {
			this.rows.add({'name': name});
		}
	};
	_.extend(cur_grim, Backbone.Events); // Make event-able
	
	// Set up Models/Views
	cur_grim.model = new Grimoire();
	cur_grim.model.on('all', function(name) { console.log('cur_grim: ', name); });
	cur_grim.model.on('change:name', function(model, newValue) {
		if (cur_grim.writeAccess) model.save();
	});

	cur_grim.rows = new GrimoireRows();
	cur_grim.rows.on('all', function(name) { console.log('rows: ', name); });
	
	cur_grim.headerView = new GrimoireHeader({
		model: cur_grim.model,
		el: $curGrim.find('div#grim_header').get(0)
	});
	cur_grim.on('change:permission', function() {
		cur_grim.headerView.render();
		
		if (cur_grim.writeAccess && Modernizr.localstorage) {
			// Save admin key locally
			localStorage.setItem('grimoire.'+cur_grim.model.get('public_key'), cur_grim.model.get('admin_key'));
		}
	});
	
	cur_grim.rowsView = new GrimoireRowsView({
		model: cur_grim.rows,
		el: $curGrim.find('ul#grim_slots').get(0)
	});
	
	if (window.location.hash) {
		// See if the hash is a valid grimoire
		cur_grim.model.set('public_key', window.location.hash.substring(1));
		
		var $xhr = cur_grim.model.fetch({
			success: function(model, response) {
				// This is a valid Grimoire
				cur_grim.rows.reset(response.rows); // Save the row collection
				cur_grim.saveRecent();

				$page_loader.hide();
				$curGrim.show();
				
			}
		});
		cur_grim.parseWriteHeader($xhr);
	} else {
		// Work on a local Grimoire
		cur_grim.model.on('change:public_key', function(model, newValue) {
			// We have a new server ID for this Grimoire
			window.location.hash = '#'+model.myKey()
			cur_grim.saveRecent();
			if (Modernizr.localstorage) {
				// Save admin key locally
				localStorage.setItem('grimoire.'+cur_grim.model.get('public_key'), cur_grim.model.get('admin_key'));
			}
		});
		
		$page_loader.hide();
		$curGrim.show();
		cur_grim.setAccess(true);
	}
	
	// New slot save action
	$('input#new_slot_text').on('keydown', function(e) {
		if (e.which == 9 || e.which == 13) {
			// Tab or enter pressed
			var $new = $(this);
			if ($new.val() == '') {
				// Empty field, just do the default tab action
				return;
			}
			
			e.preventDefault(); // Stay in this field
			cur_grim.addSlot($new.val());
			
			$new.val(''); // Clear input
		}
	});
	
	// Admin link interaction
	$(document).on('click', '#grim_header #grim_link_icon', function(e) {
		$('#grim_header #grim_link_content').slideToggle('fast');
	});
	$(document).on('click dblclick', '#grim_link_content dd', function(e) {	selectText(this); });
	
	// Heartbeat
	setInterval(cur_grim.doHeartbeat, 8000);
});

function selectText(el) {
	if (window.getSelection) {
		var selection = window.getSelection();
		if (selection.setBaseAndExtent) {
			// Safari
			selection.setBaseAndExtent(el, 0, el, 1);
		} else {
			// Mozilla, Opera
			var range = document.createRange();
			range.selectNodeContents(el);
			selection.removeAllRanges();
			selection.addRange(range);
		}
	} else {
		// IE
		var range = document.body.createTextRange();
		range.moveToElementText(el);
		range.select();
	}
}

/*
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