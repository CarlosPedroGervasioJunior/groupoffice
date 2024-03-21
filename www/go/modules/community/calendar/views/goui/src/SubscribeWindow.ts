import {btn, column, comp, datasourcestore, t, Table, table, Window} from "@intermesh/goui";
import {jmapds} from "@intermesh/groupoffice-core";

export class SubscribeWindow extends Window {

	grid: Table
	constructor() {
		super();
		this.title = t('Subscribe to calendar');
		this.height = 800;
		const store = datasourcestore({
			queryParams:{
				filter:{isSubscribed: false},
			},
			sort:[{property:'name',isAscending:true}],
			dataSource:jmapds('Calendar')
		});

		this.on('render', () => {
			store.load();
		} )

		this.items.add(comp({cls:'scroll', flex:1},this.grid = table({
			//fitParent:true,
			style:{width:'100%'},
			headers: false,
			store,
			columns: [
				column({id:'name'}),
				column({id:'id', width:120, renderer: v=> btn({
						text: "Subscribe",
						cls:'primary',
						handler: () => { store.dataSource.update(v, {isSubscribed: true}); }
					})
				})
			]
		})));
	}
}