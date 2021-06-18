go.modules.community.tasks.CategoriesGrid = Ext.extend(go.grid.GridPanel, {
	viewConfig: {
		scrollOffset: 0,
		forceFit: true,
		autoFill: true
	},
	hideHeaders: true,
	multiSelectToolbarEnabled : false,
	initComponent: function () {

		var actions = this.initRowActions();

		var selModel = new Ext.grid.CheckboxSelectionModel();
		
		var tbar = {
			xtype: "container",
			items:[
				{
					items: this.tbar, 
					xtype: 'toolbar'
				},
				this.selectAllToolbar = new Ext.Toolbar({
					items:[{xtype: "selectallcheckbox"}],
					hidden: true
				})
			]
		};

		Ext.apply(this, {
			
			tbar: tbar,
			
			store: new go.data.Store({
				fields: ['id', 'name'],
				entityStore: "TaskCategory"
			}),
			selModel: selModel,
			plugins: [actions],
			columns: [
				selModel,
				{
					id: 'name',
					header: t('Name'),
					sortable: false,
					dataIndex: 'name',
					hideable: false,
					draggable: false,
					menuDisabled: true
				},
				actions
			],

			stateful: true,
			stateId: 'categories-grid'
		});

		go.modules.community.tasks.CategoriesGrid.superclass.initComponent.call(this);

		this.store.on("load", () => {
			this.selectAllToolbar.setVisible(this.store.getCount() > 1);
		});
	},

	initRowActions: function () {

		var actions = new Ext.ux.grid.RowActions({
			menuDisabled: true,
			hideable: false,
			draggable: false,
			fixed: true,
			header: '',
			hideMode: 'display',
			keepSelection: true,

			actions: [{
					iconCls: 'ic-more-vert'
				}]
		});

		actions.on({
			action: function (grid, record, action, row, col, e, target) {
				this.showMoreMenu(record, e);
			},
			scope: this
		});

		return actions;

	},
	
	
	showMoreMenu : function(record, e) {
		if(!this.moreMenu) {
			this.moreMenu = new Ext.menu.Menu({
				items: [
					{
						itemId: "edit",
						iconCls: 'ic-edit',
						text: t("Edit"),
						handler: function() {
							var dlg = new go.modules.community.tasks.CategoryDialog();
							dlg.load(this.moreMenu.record.id).show();
						},
						scope: this						
					},{
						itemId: "delete",
						iconCls: 'ic-delete',
						text: t("Delete"),
						handler: function() {
							Ext.MessageBox.confirm(t("Confirm delete"), t("Are you sure you want to delete this item?"), function (btn) {
								if (btn != "yes") {
									return;
								}
								go.Db.store("TaskCategory").set({destroy: [this.moreMenu.record.id]});
							}, this);
						},
						scope: this						
					}
				]
			});
		}
		
		this.moreMenu.getComponent("edit").setDisabled(record.get("permissionLevel") < go.permissionLevels.manage);
		this.moreMenu.getComponent("delete").setDisabled(record.get("permissionLevel") < go.permissionLevels.manage);
		
		this.moreMenu.record = record;
		
		this.moreMenu.showAt(e.getXY());
	}
});
