go.modules.community.tasks.TasklistsGrid = Ext.extend(go.NavGrid, {
	autoHeight: true,
	scrollLoader: false,
	showMoreLoader: true,
	loadMorePageSize: 20,
	initComponent: function () {

		Ext.apply(this, {
			store: new go.data.Store({
				fields: ['id', 'name'],
				entityStore: "TaskList",
				filters: {role: {role: 'list'}},
				sortInfo: {
					field: 'name',
					direction: 'ASC'
				}
			}),

			menuItems: [
				{
					itemId: "edit",
					iconCls: 'ic-edit',
					text: t("Edit"),
					handler: function() {
						var dlg = new go.modules.community.tasks.TasklistDialog();
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
							go.Db.store("TaskList").set({destroy: [this.moreMenu.record.id]});
						}, this);
					},
					scope: this
				}
			],

			stateful: true,
			stateId: 'task-lists-grid'
		});

		go.modules.community.tasks.TasklistsGrid.superclass.initComponent.call(this);

		this.on('beforeshowmenu', (menu, record) => {
			menu.getComponent("edit").setDisabled(record.get("permissionLevel") < go.permissionLevels.manage);
			menu.getComponent("delete").setDisabled(!go.Modules.get("community", 'tasks').userRights.mayChangeTasklists || record.get("permissionLevel") < go.permissionLevels.manage);
		});
	},

	

});
