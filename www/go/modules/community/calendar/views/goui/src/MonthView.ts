import {CalendarView} from "./CalendarView.js";
import {ComponentEventMap, createComponent, DateInterval, DateTime, ObservableListenerOpts} from "@intermesh/goui";
import {E} from "@intermesh/goui";
import {CalendarEvent, CalendarItem} from "./CalendarItem.js";

// add selectweek event

export interface MonthViewEventMap<Type> extends ComponentEventMap<Type> {
	selectweek: (me: Type, day: DateTime) => false | void
}

export interface MonthView extends CalendarView {
	on<K extends keyof MonthViewEventMap<this>, L extends Function>(eventName: K, listener: Partial<MonthViewEventMap<this>>[K], options?: ObservableListenerOpts): L
	fire<K extends keyof MonthViewEventMap<this>>(eventName: K, ...args: Parameters<MonthViewEventMap<any>[K]>): boolean
}
export class MonthView extends CalendarView {

	start!: DateTime
	dragData?: CalendarEvent

	weekRows: [DateTime, HTMLElement][] = []

	protected internalRender() {
		this.makeDraggable(this.el);

		this.el.tabIndex = 0;
		this.el.on('keydown', (e: KeyboardEvent) => {
			if(e.key == 'Delete') {
				this.selected.forEach(item => {
					const i = this.viewModel.indexOf(item);
					if(i > -1) {
						item.remove();
					}
				});
			}
		});
		return super.internalRender();
	}

	goto(date: DateTime, days?: number) {
		//this.el.cls('reverse',(day < this.day));
		this.day = date.setHours(0,0,0, 0);
		this.start = date.clone()
		const endMonth = this.start.clone();
		if(days) {
			this.start.setWeekDay(0);
			this.days = days;
			endMonth.addDays(days);
		} else { // take full month
			this.start.setDate(1).setWeekDay(0);
			endMonth.setDate(1).setWeekDay(0).addDays(6*7);
			this.days = this.start.diff(endMonth).getTotalDays()!;
		}

		this.adapter.goto(this.start, endMonth);
	}

	private makeDraggable(el: HTMLElement) {
		let from : HTMLElement,
			till: HTMLElement,
			last: HTMLElement,
			anchor: HTMLElement,
			ev: CalendarItem,
			action: (day:HTMLElement) => void;

		const create = (day: HTMLElement) => {
			[from, till] = (anchor.compareDocumentPosition(day) & 0x02) ? [day,anchor] : [anchor,day];
			ev.start = new DateTime(from.dataset.date!+' 00:00:00.000');
			ev.end = new DateTime(till.dataset.date!+' 00:00:00.000').addDays(1);
		},
		move = (day:HTMLElement) => {
			let [y,m,d] = day.dataset.date!.split('-').map(Number);
			ev.start.setYear(y).setMonth(m).setDate(d);
			ev.end = ev.start.clone().add(new DateInterval(ev.data.duration));
		},
		mouseMove = ({target}: MouseEvent & {target: HTMLElement}) => {
			const day = target.up('li[data-date]');
			if(day && day != last) {
				last = day;
				action(day)
				Object.values(ev.divs).forEach(d => d.remove());
				ev.divs = {};
				this.updateItems();
			}
		},
		mouseUp = (e: MouseEvent) => {
			el.un('mousemove', mouseMove);
			window.removeEventListener('mouseup', mouseUp);

			ev.save( () => {
				//clean
				const i = this.viewModel.indexOf(ev)
				this.viewModel.splice(i, 1);
				this.updateItems();
			});
		};
		el.on('mousedown', (e) => {
			if(e.button !== 0) return;
			const day = e.target.up('li[data-date]');
			if(day) {
				const data = {
						start: day.dataset.date!,
						title: 'New event',
						duration: 'P1D',
						calendarId: CalendarView.selectedCalendarId,
						showWithoutTime: true
					},
					start = (new DateTime(data.start+' 00:00:00.000')),
					end = start.clone().addDays(1);
				ev = new CalendarItem({start, end, data, key: ''});
				this.viewModel.unshift(ev);
				this.updateItems();
				//this.drawEvent(ev, weekStart);
				//eventsContainer.prepend(ev.divs[0]);
				anchor = from = till = day;
				action = create;
				el.on('mousemove', mouseMove);
				window.addEventListener('mouseup', mouseUp);
			}
			const event = e.target.up('div[data-key]');
			if(event) {
				ev = this.viewModel.find(m => m.key == event.dataset.key)!;
				action = move;
				el.on('mousemove', mouseMove);
				window.addEventListener('mouseup', mouseUp);
			}
		});
	}

	protected clear() {
		this.viewModel.forEach(ev => {
			Object.values(ev.divs).forEach(d => d.remove());
		})
		this.viewModel = [];
	}

	protected populateViewModel() {
		this.clear()

		for(const item of this.adapter.items) {
			this.viewModel.push(item);
		}

		this.updateItems()
	}

	renderView() {
		this.viewModel = [];
		this.el.innerHTML = ''; //clear
		let it = 0, i =0;
		let now = new DateTime(),
			 day = this.start.clone(); // toDateString removes time

		this.el.style.height = '100%';
		this.el.cls(['+cal','+month']);
		this.el.append(E('ul',...Object.values(DateTime.dayNames).map((name,i) =>
			E('li',name.substring(0,2)).cls('current', this.day.format('Ym') == now.format('Ym') && now.getWeekDay() == i)
		))); // headers

		this.weekRows = [];
		while (it < this.days) {
			const weekStart = day.clone(),
				eventContainer = E('li').cls('events'),
				row = E('ol',eventContainer);
			for (i = 0; i < 7; i++) {
				row.append(E('li',
					i==0 ? E('sub','W '+day.getWeekOfYear()).cls('weeknb')
						.on('click',e => this.fire('selectweek', this, weekStart))
						.on('mousedown',e=>e.stopPropagation()):'',
					E('em',day.format(day.getDate() === 1 ? 'j M' : 'd'))
				).attr('data-date', day.format('Y-m-d'))
				 .cls('today', day.format('Ymd') === now.format('Ymd'))
				 .cls('past', day.format('Ymd') < now.format('Ymd'))
				 .cls('other', day.format('Ym') !== this.day.format('Ym')))

				day.addDays(1);
				it++;
			}
			this.weekRows.push([weekStart, eventContainer]);
			this.el.append(row);
		}
	}

	private updateItems() {
		this.continues = [];
		this.iterator = 0;

		this.viewModel.sort((a,b) => a.start.date < b.start.date ? -1 : 1);
		for(const [ws, container] of this.weekRows) {
			container.append(...this.drawWeek(ws));
		}
		// call draw week but re-use divs only set style ignore the return value
	}

	iterator!: number
	continues: CalendarItem[] = []

	private drawWeek(wstart: DateTime) {
		let end = wstart.clone().addDays(7),
			e: any;
		let eventEls = [];
		this.slots = {0:{},1:{},2:{},3:{},4:{},5:{},6:{}};
		let stillContinueing = [];
		while(e = this.continues.shift()) {
			eventEls.push(this.drawEvent(e, wstart));
			if(e.end.date > end.date) {
				stillContinueing.push(e); // push it back for next week
			}
		}
		this.continues = stillContinueing;

		while((e = this.viewModel[this.iterator]) && e.start.format('Ymd') < end.format('Ymd')) {
			eventEls.push(this.drawEvent(e, wstart));
			if(e.end.date > end.date) {
				this.continues.push(e);
			}
			this.iterator++;
		}
		return eventEls;
	}

	drawEvent(e: CalendarItem, weekstart: DateTime) {
		if(!e.divs[weekstart.format('YW')]) {
			e.divs[weekstart.format('YW')] = super.eventHtml(e);
		}
		return e.divs[weekstart.format('YW')]
			.css(this.makestyle(e, weekstart))
			//.attr('style',this.makestyle(e, weekstart))
			.cls('continues', weekstart.diff(e.start).getTotalDays()! < 0)
	}
}