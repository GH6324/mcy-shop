!function () {
    let table = null;
    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/shop/item/markup/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "模板名称",
                            name: "name",
                            type: "input",
                            placeholder: "请输入加价模板名称",
                            required: true
                        },
                        {
                            title: false,
                            name: "price_module",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="module-header">${i18n('同步价格模块')}</div>`);
                            }
                        },
                        {
                            title: "同步价格",
                            name: "sync_amount",
                            type: "radio",
                            dict: [
                                {id: 0, name: "不同步"},
                                {id: 1, name: "同步仓库并加价"},
                                {id: 2, name: "同步仓库"}
                            ],
                            required: true,
                            tips: "不同步：完全由本地自定义价格\n同步并加价：根据上游的商品价格实时控制盈亏\n同步上游：上游是什么价格，本地商品就是什么价格".replaceAll("\n", "<br>"),
                            change: (from, val) => {
                                val = parseInt(val);
                                switch (val) {
                                    case 0:
                                        from.hide('keep_decimals');
                                        from.hide('drift_base_amount');
                                        from.hide('drift_model');
                                        from.hide('drift_value');
                                        break;
                                    case 1:
                                        from.show('keep_decimals');
                                        from.show('drift_base_amount');
                                        from.show('drift_model');
                                        from.show('drift_value');
                                        break;
                                    case 2:
                                        from.hide('keep_decimals');
                                        from.hide('drift_base_amount');
                                        from.hide('drift_model');
                                        from.hide('drift_value');
                                        break;
                                }
                            },
                            complete: (from, val) => {
                                from.form["sync_amount"].change(from, val);
                            }
                        },
                        {
                            title: "保留小数",
                            name: "keep_decimals",
                            type: "input",
                            default: 2,
                            required: true,
                            hide: true,
                            placeholder: "请输入要保留的小数位数",
                            tips: "价格小数，最大支持6位小数"
                        },
                        {
                            title: "价格基数",
                            name: "drift_base_amount",
                            tips: "基数就是你随便设定一个商品的成本价，比如你想象一个商品的成本价是10元，那么你就把基数设定为10元。<br><br>为什么要有这个设定呢？因为每个商品都有不同的类型和价格，设定一个基数可以帮助我们计算出你想给某个商品增加的价格。通过基数，我们可以简单地推算出商品的最终价格。",
                            type: "input",
                            placeholder: "请设定一个基数",
                            default: 10,
                            required: true,
                            hide: true,
                            regex: {
                                value: "^(0\\.\\d+|[1-9]\\d*(\\.\\d+)?)$", message: "基数必须大于0"
                            }
                        },
                        {
                            title: "加价模式",
                            name: "drift_model",
                            type: "radio",
                            hide: true,
                            tips: format.success("比例加价") + " 通过基数实现百分比加价，比如你设置基数为10，那么比例设置 0.5，那么10元的商品最终售卖的价格就是：15【算法：(10*0.5)+10】<br>" + format.warning("固定金额加价") + " 通过基数+固定金额算法，得到的比例进行加价，假如基数是10，加价1.2元，那么算法得出加价比例为：1.2/10=0.12，如果一个商品为18元，你加价了1.2元，最终售卖价格则是：20.16【算法：(18*0.12)+18】",
                            dict: "markup_type"
                        },
                        {
                            title: "浮动值",
                            name: "drift_value",
                            type: "input",
                            tips: "百分比 或 金额，根据浮动类型自行填写，百分比需要用小数表示",
                            placeholder: "请设置浮动值",
                            hide: true,
                            default: 1,
                            required: true,
                            regex: {
                                value: "^(0\\.\\d+|[0-9]\\d*(\\.\\d+)?)$", message: "浮动值必须是数字 "
                            }
                        },
                        {
                            title: false,
                            name: "info_module",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="module-header">${i18n('商品信息同步')}</div>`);
                            }
                        },
                        {
                            title: "商品名称",
                            name: "sync_name",
                            type: "switch",
                            placeholder: "同步|不同步"
                        },
                        {
                            title: "商品介绍",
                            name: "sync_introduce",
                            type: "switch",
                            placeholder: "同步|不同步"
                        },
                        {
                            title: "封面图片",
                            name: "sync_picture",
                            type: "switch",
                            placeholder: "同步|不同步"
                        },
                        {
                            title: "SKU名称",
                            name: "sync_sku_name",
                            type: "switch",
                            placeholder: "同步|不同步"
                        },
                        {
                            title: "SKU封面",
                            name: "sync_sku_picture",
                            type: "switch",
                            placeholder: "同步|不同步"
                        },
                    ]
                }
            ],
            assign: assign,
            autoPosition: true,
            done: () => {
                table.refresh();
            }
        });
    }

    table = new Table("/admin/shop/item/markup/get", "#shop-markup-table");
    table.setDeleteSelector(".del-shop-markup", "/admin/shop/item/markup/del");
    table.setUpdate("/admin/shop/item/markup/save");
    table.setColumns([
        {checkbox: true},
        {field: 'user', title: '商户', formatter: format.user},
        {field: 'name', title: '模板名称'},
        {
            field: 'sync_amount', title: '同步价格', dict: [
                {id: 0, name: "🚫不同步"},
                {id: 1, name: "💲同步仓库并加价"},
                {id: 2, name: "♻️同步仓库"}
            ], text: "同步|不同步", reload: true, align: `center`
        },
        {
            field: 'drift_model', title: '加价模式', width: 170, formatter: (val, item) => {
                if (item.sync_amount != 1) {
                    return '-';
                }
                return _Dict.result('markup_type', val);
            }
        },
        {
            field: 'drift_value', title: '绝对比例', width: 120, formatter: (val, item) => {
                if (item.sync_amount != 1) {
                    return '-';
                }
                return item.drift_model == 1 ?  (new Decimal(val)).div(item.drift_base_amount).mul(100).getAmount() + "%" : (new Decimal(val)).mul(100).getAmount() + "%";
            }
        },
        {
            field: 'drift_base_amount', title: '基数', width: 120, formatter: (val, item) => {
                if (item.sync_amount != 1) {
                    return '-';
                }
                return format.amountRemoveTrailingZeros(val);
            }
        },
        {
            field: 'keep_decimals', title: '保留小数', formatter: (val, item) => {
                if (item.sync_amount != 1) {
                    return '-';
                }
                return val;
            }
        },
        {field: 'sync_name', title: '商品名称', type: 'switch', text: "同步|不同步", reload: true},
        {field: 'sync_introduce', title: '商品介绍', type: 'switch', text: "同步|不同步", reload: true},
        {field: 'sync_picture', title: '商品封面', type: 'switch', text: "同步|不同步", reload: true},
        {field: 'sync_sku_name', title: 'SKU名称', type: 'switch', text: "同步|不同步", reload: true},
        {field: 'sync_sku_picture', title: 'SKU封面', type: 'switch', text: "同步|不同步", reload: true},
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'icon-biaoge-xiugai',
                    class: 'acg-badge-h-dodgerblue',
                    tips: "修改模版",
                    click: (event, value, row, index) => {
                        modal(util.icon("icon-bianji") + " 修改模板", row);
                    }
                },
                {
                    icon: 'icon-shanchu1',
                    class: 'acg-badge-h-red',
                    tips: '删除模版',
                    click: (event, value, row, index) => {
                        component.deleteDatabase("/admin/shop/item/markup/del", [row.id], () => {
                            table.refresh();
                        });
                    }
                }
            ]
        },
    ]);
    table.setFloatMessage([
        {field: 'create_time', title: '创建时间'}
    ]);
    table.setSearch([
        {
            title: "商家，默认主站",
            name: "user_id",
            type: "remoteSelect",
            dict: "user?type=2"
        },
        {title: "模板名称(模糊搜索)", name: "search-name", type: "input"},
    ]);
    table.render();


    $('.add-shop-markup').click(() => {
        modal(util.icon("icon-tianjia") + " 添加模板");
    });
}();