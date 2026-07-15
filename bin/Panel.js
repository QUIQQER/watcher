/**
 * Watcher panel
 *
 * @module package/quiqqer/watcher/bin/Panel
 * @author www.pcsg.de (Henning Leutz)
 *
 * @require qui/QUI
 * @require qui/controls/desktop/Panel
 * @require qui/controls/messages/Attention
 * @require qui/utils/Form
 * @require controls/grid/Grid
 * @require utils/Template
 * @require utils/Controls
 * @require Locale
 * @require Ajax
 * @require css!package/quiqqer/watcher/bin/Panel.css
 */
define('package/quiqqer/watcher/bin/Panel', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/messages/Attention',
    'qui/controls/windows/Popup',
    'qui/utils/Form',
    'controls/grid/Grid',
    'utils/Template',
    'utils/Controls',
    'Locale',
    'Ajax',

    'css!package/quiqqer/watcher/bin/Panel.css'

], function (QUI, QUIPanel, QUIAttention, QUIPopup, QUIFormUtils, Grid, Template, ControlUtils, QUILocale, QUIAjax) {
    "use strict";

    var lg = 'quiqqer/watcher';

    return new Class({

        Extends: QUIPanel,
        Type   : 'package/quiqqer/watcher/bin/Panel',

        Binds: [
            'loadData',
            'openSearch',
            'openClear',
            '$onCreate',
            '$onResize'
        ],

        initialize: function (option) {
            this.setAttributes({
                icon : 'fa fa-eye',
                title: QUILocale.get('quiqqer/watcher', 'panel.title')
            });

            this.parent(option);

            this.$Grid = null;

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize,
                onInject: this.$onInject
            });
        },

        /**
         * event : on create
         */
        $onCreate: function () {
            // Buttons
            this.addButton({
                text     : QUILocale.get(lg, 'panel.button.search.title'),
                textimage: 'fa fa-search',
                events   : {
                    onClick: this.openSearch
                }
            });

            // Buttons
            this.addButton({
                text     : QUILocale.get(lg, 'panel.button.clear.title'),
                textimage: 'fa fa-eraser',
                events   : {
                    onClick: this.openClear
                }
            });

            // Grid
            var Container = new Element('div').inject(
                this.getContent()
            );

            this.$Grid = new Grid(Container, {
                columnModel: [{
                    header   : QUILocale.get('quiqqer/system', 'id'),
                    dataIndex: 'id',
                    dataType : 'number',
                    width    : 60
                }, {
                    header   : QUILocale.get('quiqqer/system', 'user_id'),
                    dataIndex: 'uid',
                    dataType : 'string',
                    width    : 100
                }, {
                    header   : QUILocale.get('quiqqer/system', 'username'),
                    dataIndex: 'username',
                    dataType : 'string',
                    width    : 100
                }, {
                    header   : QUILocale.get('quiqqer/system', 'date'),
                    dataIndex: 'statusTime',
                    dataType : 'date',
                    width    : 140
                }, {
                    header   : QUILocale.get(lg, 'grid.message'),
                    dataIndex: 'message',
                    dataType : 'string',
                    width    : 300
                }, {
                    header   : QUILocale.get(lg, 'grid.call'),
                    dataIndex: 'call',
                    dataType : 'string',
                    width    : 200
                }, {
                    header   : QUILocale.get(lg, 'grid.callParams'),
                    dataIndex: 'callParams',
                    dataType : 'code',
                    width    : 200
                }],
                onrefresh  : this.loadData,
                pagination : true,
                serverSort : true,
                sortOn     : 'statusTime',
                sortBy     : 'DESC'
            });

            if (this.getAttribute('search')) {
                this.showSearchDisplay();
            }

            this.$Grid.addEvents({
                onDblClick: function () {
                    this.openEntry(this.$Grid.getSelectedIndices()[0]);
                }.bind(this)
            });
        },

        openEntry: function (row) {
            var data = this.$Grid.getDataByRow(row);

            new QUIPopup({
                title    : data.message,
                maxHeight: 600,
                maxWidth : 800,
                events   : {
                    onOpen: function (Win) {
                        var key, Code, Main;
                        var List = new Element('ul');

                        for (key in data) {
                            if (!data.hasOwnProperty(key)) {
                                continue;
                            }

                            Main = new Element('li', {
                                html: key + ':'
                            }).inject(List);

                            Code = new Element('code', {
                                text: data[key]
                            });

                            Code.inject(Main);
                        }

                        List.inject(Win.getContent());
                    }
                }
            }).open();
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            this.loadData();
        },

        /**
         * event : on resize
         */
        $onResize: function () {
            if (!this.$Grid) {
                return;
            }

            var Content = this.getContent();

            if (!Content) {
                return;
            }


            var size      = Content.getSize(),
                Attention = Content.getElement('.messages-message');

            if (Attention) {
                this.$Grid.setHeight(size.y - Attention.getSize().y - 60);
            } else {
                this.$Grid.setHeight(size.y - 40);
            }

            this.$Grid.setWidth(size.x - 40);
        },

        /**
         * Load the grid data
         */
        loadData: function () {
            if (!this.$Grid) {
                return;
            }

            this.Loader.show();

            var self    = this;
            var options = this.$Grid.options;

            var search = false;

            if (this.getAttribute('search')) {
                search = JSON.encode(this.getAttribute('search'));
            }

            QUIAjax.get('package_quiqqer_watcher_ajax_list', function (result) {
                self.$Grid.setData(result);
                self.Loader.hide();

            }, {
                'package': 'quiqqer/watcher',
                params   : JSON.encode({
                    sortOn : options.sortOn,
                    sortBy : options.sortBy,
                    perPage: options.perPage,
                    page   : options.page
                }),
                search   : search
            });
        },

        /**
         * Open search sheet
         */
        openSearch: function () {
            var self = this;

            var Sheet = this.createSheet({
                title      : QUILocale.get(lg, 'panel.search.title'),
                icon       : 'fa fa-search',
                closeButton: {
                    textimage: 'fa fa-remove',
                    text     : QUILocale.get(lg, 'panel.search.button.close')
                },
                events     : {
                    onOpen: function (Sheet) {

                        self.Loader.show();

                        var Content = Sheet.getContent(),
                            search  = self.getAttribute('search');

                        Content.addClass('quiqqer-watcher-search');

                        Template.get('bin/PanelSearch', function (result) {

                            Content.set({
                                html  : result,
                                styles: {
                                    padding: 20
                                }
                            });

                            QUIFormUtils.setDataToForm(
                                search,
                                Content.getElement('form')
                            );

                            ControlUtils.parse(Content).then(function () {
                                self.Loader.hide();
                            });

                        }, {
                            'package': 'quiqqer/watcher'
                        });
                    }
                }
            });

            Sheet.addButton({
                text     : QUILocale.get(lg, 'panel.search.button.search'),
                textimage: 'fa fa-search',
                events   : {
                    onClick: function () {

                        var data = QUIFormUtils.getFormData(
                            Sheet.getContent().getElement('form')
                        );

                        self.setAttributes({
                            search: {
                                uid : data.uid,
                                from: data.from,
                                to  : data.to
                            }
                        });

                        Sheet.hide();

                        self.loadData();
                        self.showSearchDisplay();
                    }
                }
            });

            Sheet.show();
        },

        /**
         * open clear sheet
         */
        openClear: function () {
            var self = this;

            var Sheet = this.createSheet({
                title      : QUILocale.get(lg, 'panel.clear.title'),
                icon       : 'fa fa-search',
                closeButton: {
                    textimage: 'fa fa-remove',
                    text     : QUILocale.get('quiqqer/system', 'cancel')
                },
                events     : {
                    onOpen: function (Sheet) {

                        self.Loader.show();

                        var Content = Sheet.getContent();

                        Content.addClass('quiqqer-watcher-clear');

                        Template.get('bin/PanelClear', function (result) {

                            Content.set({
                                html  : result,
                                styles: {
                                    padding: 20
                                }
                            });

                            ControlUtils.parse(Content).then(function () {
                                self.Loader.hide();
                            });

                        }, {
                            'package': 'quiqqer/watcher'
                        });
                    }
                }
            });

            Sheet.addButton({
                text     : QUILocale.get('quiqqer/system', 'panel.clear.btn.execute'),
                textimage: 'fa fa-eraser',
                events   : {
                    onClick: function () {

                        self.Loader.show();

                        var Content = Sheet.getContent(),
                            date    = '';

                        if (Content.getElement('[name="watcher-clear-date"]')) {
                            date = Content.getElement('[name="watcher-clear-date"]').value;
                        }

                        if (!date || date === '') {
                            self.Loader.hide();
                            return;
                        }

                        QUIAjax.post('package_quiqqer_watcher_ajax_clear', function () {

                            Sheet.hide();
                            self.loadData();

                        }, {
                            'package': 'quiqqer/watcher',
                            date     : date
                        });

                    }
                }
            });

            Sheet.show();
        },

        /**
         * Show search display
         */
        showSearchDisplay: function () {
            if (this.getContent().getElement('.messages-message')) {
                return;
            }

            var self = this;

            new QUIAttention({
                message: QUILocale.get(lg, 'grid.search.info'),
                events : {
                    onClick: function (Message) {
                        self.setAttribute('search', false);

                        Message.destroy();

                        self.loadData();
                        self.$onResize();
                    }
                },
                styles : {
                    margin        : '0 0 20px',
                    'border-width': 1,
                    cursor        : 'pointer'
                }
            }).inject(this.getContent(), 'top');

            this.$onResize();
        }
    });
});
