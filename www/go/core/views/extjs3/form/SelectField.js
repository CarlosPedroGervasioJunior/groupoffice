/**
 * Simple select field
 *
 * @example
 *
 * {
    xtype: 'selectfield',
    name: 'encryption',
    fieldLabel: t('Encryption'),
    options: [['tls', 'TLS'], ['ssl', 'SSL'], [null, 'None']],
    value: 'tls'
  }
 */
go.form.SelectField = Ext.extend(go.form.ComboBox, {

    options: {},
    valueField: 'value',
    displayField: 'text',
    mode: 'local',
    triggerAction: 'all',
    editable: false,
    forceSelection: true,

    initComponent : function(){

        this.store = new Ext.data.ArrayStore({
            fields: ['value', 'text'],
            id: 'value',
            data: this.options
        });

        go.form.SelectField.superclass.initComponent.call(this);
    }
});

Ext.reg('selectfield', go.form.SelectField);