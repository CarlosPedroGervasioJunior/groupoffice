import {SelectField, Config, createComponent, FieldEventMap} from "@intermesh/goui";
import {t} from "./Index.js";

interface Alert {
	trigger:any // {offset, relativeTo} | {when}
	acknowledged?:string
	action?:'display'|'email'
}

export class AlertField extends SelectField {

	fullDay = false
	isForDefault = false
	constructor() {
		super();
		this.name = 'alerts';
		this.label = t('Alerts');
	}

	drawOptions() {
		this.options = this.fullDay ? [
			{value: '-P1D', name: '1 '+ t('day')+' '+t('before')},
			{value: '-P2D', name: '2 '+ t('days')+' '+t('before')}
		] : [
			{value: '-PT5M', name: '5 '+ t('minutes')+' '+t('before')},
			{value: '-PT10M', name: '10 '+ t('minutes')+' '+t('before')},
			{value: '-PT15M', name: '15 '+ t('minutes')+' '+t('before')},
			{value: '-PT1H', name: '1 '+ t('hour')+' '+t('before')},
			{value: '-PT2H', name: '2 '+ t('hours')+' '+t('before')},
			{value: 'P0D', name: t('At the start')},
		];
		if(!this.isForDefault) {
			super.value = 'default';
			this.options.unshift({value: 'default', name: t('Default')});
		}
		this.options.unshift({value: null, name: t('None')})
		super.drawOptions();
	}

	get value() {
		const v = super.value;
		return (v && v !== 'default') ? {1:{trigger:{offset:v}}} : {};
	}

	set value(v: {[id:string]:Alert}) {
		if(!v) {
			super.value = v;
			return;
		}
		const firstKey = Object.keys(v)[0];
		if(v[firstKey]) {
			super.value = (v && v[firstKey].trigger) ? v[firstKey].trigger.offset : v;
		}
	}

	setDefaultLabel(alerts?: {[id:string]:Alert}) {
		var txt = t('None');
		if (alerts) {
			const firstKey = Object.keys(alerts)[0];
			if(alerts[firstKey]) {
				const duration = this.parseDuration(alerts[firstKey].trigger.offset);
				txt = this.durationToText(duration);
			}
		}
		this.input!.options[1].innerText = t('Default') + ' ('+txt+')';
	}

	private durationToText(duration:any) {
		let str = [];
		// if(duration.year) {
		// 	str += (duration.year) + ' ' + (duration.year === 1 ? t('year') : t('years')));
		// }
		if(duration.day) {
			str.push(duration.day + ' ' + (duration.day === 1 ? t('day') : t('days')));
		}
		if(duration.hour) {
			str.push(duration.hour + ' ' + (duration.day === 1 ? t('hour') : t('hours')));
		}
		if(duration.minute) {
			str.push(duration.minute + ' ' + (duration.day === 1 ? t('minute') : t('minutes')));
		}

		return str.join(', ') + ' ' + (duration.negative ? t('before') : t('after'));
	}

	private parseDuration(val: string) {
		var value: any = {negative:false},
			p = val.split('');
		if(p[0] == '-') {
			value.negative = true;
			p.shift();
		}
		if(p[0] == 'P') p.shift();
		let time = false;
		let num = '';
		for(const char of p) {
			if(/[A-Z]/.test(char!)) {
				const n = parseFloat(num);
				switch(char) {
					case 'T': time = true; break;
					case 'Y': value.year = n; break;
					case 'M': time ? (value.minute = n) : (value.month = n); break;
					case 'D': value.day = n; break;
					case 'W': value.week = n; break;
					case 'H': value.hours = n; break;
					case 'S': value.seconds = n; break;
				}
				num = '';
			} else {
				num += char;
			}
		}
		return value;
	}

}

export const alertfield = (config?: Config<AlertField, FieldEventMap<AlertField>>) => createComponent(new AlertField(), config);