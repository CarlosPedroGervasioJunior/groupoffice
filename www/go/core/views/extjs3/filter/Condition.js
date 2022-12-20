go.filter.Condition = Ext.extend(go.form.FormContainer, {
	entity: null,
	
	layout: "column",

	initComponent: function () {
		this.filters = go.Entities.get(this.entity).filters;
		this.filters['subconditions'] = {
			name: "subconditions",
			title: t("Sub conditions"),
			type: go.filter.types.subconditions
		};

		//this.filters.columnSort('title');

		this.items = [this.createFilterCombo()];		
		
		go.filter.Condition.superclass.initComponent.call(this);

	},

	createFilterCombo: function () {
		this.filterCombo = new go.form.ComboBox({
			width: dp(300),
			hideLabel: true,
			name: "name",
			store: new Ext.data.JsonStore({
				fields: ['name', 'title'],
				root: 'data',
				data: {data: Object.values(this.filters)},
				remoteSort: false
			}),
			valueField: 'name',
			displayField: 'title',
			mode: 'local',
			triggerAction: 'all',
			editable: true,
			selectOnFocus: true,
			forceSelection: true,
			allowBlank: false,
			listeners: {
				scope: this,
				select: this.onFieldSelect
//				change: this.onFieldChange
			}
		});

		this.filterCombo.store.sort('title');
		
		return this.filterCombo;
	},
	
	onFieldSelect : function(combo, record, index) {

		this.items.each(function(i) {
			if(i === this.filterCombo) {
				return;
			}
			
			this.remove(i, true);
		}, this);
		
		this.switchCondition(this.filters[record.data.name]);
		
		this.doLayout();
		
	},
	
	setValue : function(v) {		
		
		if(v) {
			var filter = this.filters[v.name];
			if(!filter) {
				return;
			}

			this.switchCondition(filter);
		}
		
		go.filter.Condition.superclass.setValue.call(this, v);
		
	},	
	
	switchCondition : function(filter) {
		
		var cls;
		
		if(go.filter.types[filter.type]) {
			cls = go.filter.types[filter.type];
		}else
		{
			try {
				cls = eval(filter.type);
			} catch(e) {
				throw "Invalid filter type '" + filter.type + "' in definition '" +this.entity+ "' " + JSON.stringify(filter, null, 1);
			}
		}

		if(!filter.typeConfig) {
			filter.typeConfig = {};
		}

		Ext.apply(filter.typeConfig, {
			columnWidth: 1,
			filter: filter,
			name: 'value',
			hiddenName: 'value',
			customfield: filter.customfield //Might be null if this is a standard filter.
		});
		
		this.add(new cls(filter.typeConfig));				
	}
	
});

Ext.reg("filtercondition", go.filter.Condition);
