/* global go, Ext, GO, mcrypt */

go.modules.community.tasks.TaskDetail = Ext.extend(go.detail.Panel, {
	
	entityStore: "Task",
	stateId: 'ta-tasks-detail',
	relations: ["tasklist", "responsible", 'categories'],
	cls: "go-detail-view tasks-task",

	initComponent: function () {


		this.tbar = this.initToolbar();

		this.progressMenu = new Ext.menu.Menu({
			cls: "x-menu-no-icons",
			items: [
				{
					text: t("Needs action"),
					handler: () => {
						this.changeProgress("needs-action");
					}
				},{
					text: t("In Progress"),
					handler: () => {
						this.changeProgress("in-progress");
					}
				},{
					text: t("Completed"),
					handler: () => {
						this.changeProgress("completed");
					}
				},{
					text: t("Failed"),
					handler: () => {
						this.changeProgress("failed");
					}
				},{
					text: t("Cancelled"),
					handler: () => {
						this.changeProgress("cancelled");
					}
				}
			]
		});

		Ext.apply(this, {
			items: [{
				tpl: new Ext.XTemplate('<h3 class="title s8" style="{[values.color ? \'color:#\'+values.color : \'\']}">{title}</h3>\
					<h4 class="status {[this.progressColor(values.progress)]}-fill">{[go.modules.community.tasks.progress[values.progress]]}</h4>\
				<p class="s6 pad">\
					<label>'+t("Start at")+'</label><span>{[go.util.Format.date(values.start) || "-"]}</span><br><br>\
					<label>'+t("Tasklist")+'</label><span><tpl for="tasklist">{name}</tpl></span><br><br>\
					<tpl if="values.recurrenceRule"><label>'+t('Recurrence')+'</label><span>{[this.rruleToText(values.recurrenceRule)]}</span><br><br></tpl>\
				</p>\
				<p class="s6">\
					<label>'+t("Due at")+'</label><span>{[go.util.Format.date(values.due) || "-"]}</span><br><br>\
					<tpl if="values.responsible"><label>'+t("Responsible")+'</label><span>{[go.util.avatar(values.responsible.displayName, values.responsible.avatarId)]} {[values.responsible.displayName]}</span><br><br></tpl>\
				</p>\
				<tpl if="values.percentComplete">\
				<div class="s12 pad">\
					<label>'+t("Percent complete")+'</label>\
					<div class="go-progressbar" style="clear:both"><div style="width:{[Math.ceil(values.percentComplete)]}%"></div></div>\
				</div>\
				</tpl>\
				<tpl if="!GO.util.empty(description)"><p class="s12 pad">\
					<label>'+t('Description')+'</label>\
					<span>{[go.util.textToHtml(values.description)]}</span>\
				</p></tpl>\
				<tpl if="!GO.util.empty(location)"><p class="s12 pad">\
					<label>'+t('Location')+'</label>\
					<span>{[go.util.textToHtml(values.location)]}</span>\
				</p></tpl>\
				<tpl if="categories.length">\
					<p class="s12 pad">\
					<label>'+t('Categories')+'</label>\
					<span>\
						<tpl for="categories"><span class="tasks-category">{name}</span></tpl>\
					</span>\
					</p>\
				</tpl>',{
					rruleToText: function(rrule) {
						var fieldDummy = new go.form.RecurrenceField();
						return fieldDummy.parseRule(rrule);
					},
					progressColor: function(p) {
						return {
							'needs-action': 'yellow',
							'in-progress': 'blue',
							'completed': 'green',
							'failed': 'red',
							'cancelled': 'bluegrey'
						}[p] || 'cyan';
					}
				}),
				listeners : {
					afterrender: (item) => {
						item.getEl().on("click", (e) => {
							if(e.target.tagName != 'H4') {
								return;
							}


							this.progressMenu.showAt(e.xy);

						});
					}
				}
			}],

			buttons: [{
				iconCls: 'ic-forward',
				text:t("Continue task", "tasks"),
				handler:() => {
					const continueTaskDialog = new go.modules.community.tasks.ContinueTaskDialog();
					continueTaskDialog.load(this.currentId).show();
				}
			}]
		});
		

		go.modules.community.tasks.TaskDetail.superclass.initComponent.call(this);
		this.addCustomFields();
		this.addComments();
		this.addLinks();
		this.addFiles();
		this.addHistory();

		this.on("destroy" , () => {
			this.progressMenu.destroy();
		})
	},


	changeProgress : function(progress) {
		go.Db.store("Task").save({
			progress: progress
		}, this.data.id);
	},


	onLoad: function () {
		this.getTopToolbar().getComponent("edit").setDisabled(this.data.permissionLevel < go.permissionLevels.write);
		this.deleteItem.setDisabled(this.data.permissionLevel < go.permissionLevels.writeAndDelete);

		this.assignMeBtn.setVisible(!this.data.responsibleUserId);

		go.modules.community.tasks.TaskDetail.superclass.onLoad.call(this);
	},

	initToolbar: function () {

		var items = this.tbar || [];

		items = items.concat([
			this.assignMeBtn = new Ext.Button({
				text: t("Assign me"),
				scope: this,
				handler: function() {
					go.Db.store("Task").save({
						responsibleUserId: go.User.id,
						progress: "in-progress"
					}, this.data.id);
				}
			}),
			'->',
			this.editTaskBtn = new Ext.Button({
				itemId: "edit",
				iconCls: 'ic-edit',
				tooltip: t("Edit"),
				handler: function (btn, e) {
					const taskEdit = new go.modules.community.tasks.TaskDialog();
					taskEdit.load(this.data.id).show();
				},
				scope: this
			}),

			new go.detail.addButton({
				detailView: this
			}),

			this.moreMenu = {
				iconCls: 'ic-more-vert',
				menu: [
					{
						xtype: "linkbrowsermenuitem"
					},
					'-',
					{
						iconCls: "ic-print",
						text: t("Print"),
						handler: function () {
							this.body.print({title: this.data.name});
						},
						scope: this
					}, "-",
					this.deleteItem = new Ext.menu.Item({
						itemId: "delete",
						iconCls: 'ic-delete',
						text: t("Delete"),
						handler: function () {
							Ext.MessageBox.confirm(t("Confirm delete"), t("Are you sure you want to delete this item?"), function (btn) {
								if (btn !== "yes") {
									return;
								}
								this.entityStore.set({destroy: [this.currentId]});
							}, this);
						},
						scope: this
					})
				]
			}]);
		
		if(go.Modules.isAvailable("legacy", "files")) {
			this.moreMenu.menu.splice(1,0,{
				xtype: "filebrowsermenuitem"
			});
		}

		var tbarCfg = {
			disabled: true,
			items: items
		};

		return new Ext.Toolbar(tbarCfg);
	}
});
