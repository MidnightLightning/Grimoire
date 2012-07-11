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
	},
});
var GrimoireRows = Backbone.Collection.extend({
	model: GrimoireRow,
});
var Grimoire = Backbone.Model.extend({
	defaults: {
		'name':'New Grimoire'
	},
	idAttribute: "public_key",
	urlRoot: 'api/grimoire/',
	url: function() {
		return this.urlRoot+this.myKey();
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
		cur_grim.writeAccess = true;
	}
	
	// New slot save action
	$('input#new_slot_text').on('keydown', function(e) {
		if (e.which == 9 || e.which == 13) {
			// Tab or enter pressed
			e.preventDefault(); // Stay in this field
			var $new = $(this);
			addSlot($new.val());
			
			$new.val(''); // Clear input
		}
	});
	
});

function parseWriteHeader($xhr) {
	if ($xhr.state() == 'pending') {
		setTimeout(function() { parseWriteHeader($xhr); }, 200); // Wait until complete
	} else {
		cur_grim.writeAccess = ($xhr.getResponseHeader('GRIMOIRE-WRITE-ACCESS') == 'true')? true : false;
	}
}

function addSlot(name) {
	cur_grim.rows.add({'name': name});
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