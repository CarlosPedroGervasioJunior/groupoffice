/**
 * Navigation grid
 *
 * See go.modules.community.tasks.TasklistsGrid for an example
 */
go.NavGrid = Ext.extend(go.grid.GridPanel,{
	viewConfig: {
		scrollOffset: 0,
		forceFit: true,
		autoFill: true,
		totalDisplay: false
	},
	multiSelectToolbarEnabled : false,
	hideHeaders: true,
	hideMenuButton: false,
	filteredStore: null,
	filterName: null,
	selectFirst: false,
	saveSelection: false,
	selectAllButton: true,
	singleSelect: false,
	nameRenderer: (value, metaData, record, rowIndex, colIndex, store) => value,
	loadFilteredStoreWithoutSelection: true,


	initColumns : function() {
		const actions = this.initRowActions();
		this.plugins = [actions];

		if(this.singleSelect) {
			this.selModel = new Ext.grid.RowSelectionModel({singleSelect: true});
		} else
		{
			this.selModel = new Ext.grid.CheckboxSelectionModel({renderer : function(v, p, record){
				const s = !record.data.color ? '' : ' style="color:#'+record.data.color+';"';
				return `<div class="x-grid3-row-checker"${s}>&#160;</div>`;
			}});
		}

		if(!this.columns) {
			this.columns = [
				{
					id: 'name',
					header: t('Name'),
					sortable: false,
					dataIndex: 'name',
					hideable: false,
					draggable: false,
					menuDisabled: true,
					renderer: this.nameRenderer
				},
				actions
			];

			if(!this.singleSelect) {
				this.columns.unshift(this.selModel);
			}
		}
	},

	initComponent: function () {

		this.initColumns();

		if(this.selectAllButton && !this.singleSelect) {
			this.selectAllToolbar = new Ext.Toolbar({
				items: [{
					xtype: "selectallcheckbox",
					selectFirst: this.selectFirst
				}]
			})

			if (!this.tbar) {
				this.tbar = [];
			}

			this.tbar.splice(0, 0, 				{
				xtype: 'checkbox',
				style: 'margin-right: ' + dp(7) + "px",
				listeners: {
					check: function(cb, checked) {

						if (checked) {
							this.getSelectionModel().selectAll();
						} else if(this.selectFirst)
						{
							this.getSelectionModel().selectRange(0, 0);
						} else {
							this.getSelectionModel().clearSelections();
						}

					},
					scope: this
				}
			});

			this.store.on("datachanged", this.onStoreDataChanged, this);
		}



		go.NavGrid.superclass.initComponent.call(this);

		this.store.on("load", this.onStoreLoad, this);

		this.getSelectionModel().on('selectionchange', this.onSelectionChange, this, {buffer: 1}); //add buffer because it clears selection first

		if(this.saveSelection) {

			const state = Ext.state.Manager.get(this.stateId)

			if(state) {
				const ids = JSON.parse(state);
				this.setDefaultSelection(ids);
			}

			if(this.store.getCount()) {
				this.on("viewready", () => {
					this.onStoreLoad(this.store);
				})
			}
		}

	},

	setDefaultSelection : function(selectedListIds) {
		this.filteredStore.setFilter(this.getId(), {[this.filterName]: selectedListIds});
	},

	getSelectedIds: function() {
		const f = this.filteredStore.getFilter(this.getId());
		if(!f) {
			return [];
		}
		return f[this.filterName] || [];
	},

	onStoreDataChanged : function() {
		this.selectAllToolbar.setVisible(this.store.getCount() > 1);
	},

	onStoreLoad: function(store) {

		//mark selected records in the filter as seleted in the selection model
		const selected = [], selectedIds = this.getSelectedIds();

		if(this.selectFirst && selectedIds.length == 0 && store.getCount() > 0) {
			selected.push(store.getAt(0));
		}

		selectedIds.forEach((id) =>{
			const record = store.getById(id);

			if(record) {
				selected.push(record);
			}
		});

		const select = () => {
			// console.warn(selected);
			// this.getSelectionModel().suspendEvents(false)
			this.getSelectionModel().selectRecords(selected, true);
			// this.getSelectionModel().resumeEvents();
		}

		if(this.rendered) {
			select();
		} else
		{
			this.on('render', select);
		}


	},

	onSelectionChange : function (sm) {
		var ids = [];


		Ext.each(sm.getSelections(), function (r) {
			ids.push(r.id);
		}, this);


		this.filteredStore.setFilter(this.getId(), {[this.filterName]: ids});

		this.fireEvent('selectionchange', ids, sm);

		if(this.saveSelection) {
			Ext.state.Manager.set(this.stateId, JSON.stringify(ids));
		}

		if(this.loadFilteredStoreWithoutSelection || ids.length)
			this.filteredStore.load();
	},

	initRowActions: function () {

		var actions = new Ext.ux.grid.RowActions({
			menuDisabled: true,
			hideable: false,
			hidden: this.hideMenuButton,
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
				items: this.menuItems
			});
		}

		this.moreMenu.record = record;
		this.fireEvent('beforeshowmenu', this.moreMenu, record);

		this.moreMenu.showAt(e.getXY());
	}
});

Ext.reg('navgrid', go.NavGrid);