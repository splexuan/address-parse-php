# AddressParse（PHP 单文件类）

中国大陆收货地址解析：从一段混杂文本中拆出 **省 / 市 / 区 / 详细地址 / 姓名 / 手机号 / 邮编**。

Python 版 [address-parse](https://github.com/splexuan/address-parse) 的 PHP 移植，在 `app/Services` 下直接可用：

```
app/Services/
├── AddressParse.php        # 一个类文件，命名空间 App\Services
└── data/
    ├── provinceList.json   # 省/市/区 三级数据
    └── names.json          # 百家姓表
```

## 用法

```php
use App\Services\AddressParse;

$result = AddressParse::parse('浙江省杭州市西湖区文三路138号 张三 13800138000');

// [
//     'phone' => '13800138000',
//     'postalCode' => '',
//     'province' => '浙江省',
//     'city' => '杭州市',
//     'area' => '西湖区',
//     'detail' => '文三路138号',
//     'name' => '张三',
//     'provinceCode' => '330000',
//     'cityCode' => '330100',
//     'areaCode' => '330106',
// ]
```

Laravel 走 PSR-4 自动加载，`app/Services/` 下的类不用 `require`、不用 `composer dump-autoload`。
控制器里可以直接类型注入：

```php
public function store(Request $request, AddressParse $parser)
{
    return response()->json($parser->parse($request->input('address')));
}
```

> 想放别的目录（比如 `app/Support/`）就把文件里的 `namespace App\Services;` 一起改掉，与目录对应即可。

### 选项

```php
AddressParse::parse($address, [
    'type' => AddressParse::TYPE_TREE,  // 0 正则匹配（默认）/ 1 行政区划树查找
    'nameMaxLength' => 4,               // 中文姓名最大长度，默认 4（复姓/少数民族长名可调大）
    'textFilter' => ['备注', '订单号'],  // 额外要清洗掉的词
    'cache' => true,                    // 启用持久化文件缓存（默认 false，见下方「缓存」）
    'cachePath' => '/var/cache/addressparse.cache', // 自定义缓存文件路径（可选）
    'extraGovData' => [                 // 本次调用临时补充的区划（不影响后续调用）
        'province' => [['code' => '990000', 'name' => '测试省']],
        'city'     => [['code' => '990100', 'name' => '测试市', 'provinceCode' => '990000']],
        'area'     => [['code' => '990101', 'name' => '测试区', 'cityCode' => '990100', 'provinceCode' => '990000']],
    ],
]);
```

键名同时支持 snake_case（`name_max_length` / `text_filter` / `extra_gov_data` / `cache_path`），直接把请求参数丢进去也不会炸。
参数非法（`type` 传 `abc`、`nameMaxLength` 传汉字）会静默回落到默认值，不抛异常。

### 缓存

PHP-FPM / mod_php 下每个请求都是新进程，类里的静态属性只在单次请求内复用；不开缓存时每个请求都要重新读取并 `json_decode` 约 140KB 的 `provinceList.json`、再建一次索引。

开启后会把「已解析好的区划数据」序列化成一个缓存文件，下个请求直接反序列化，跳过读文件 + JSON 解析 + 建索引。实测冷启动从约 175ms 降到约 11ms，解析结果完全一致。

```php
// 方式一：全局开启一次（推荐，放在 AppServiceProvider::boot() 里）
AddressParse::setCache(true);                                        // 默认缓存到 data/addressparse.cache
AddressParse::setCache(true, storage_path('app/addressparse.cache')); // 自定义路径

// 方式二：按调用开启
AddressParse::parse($address, ['cache' => true]);
```

- 缓存文件比源 json 旧（你更新了区划数据）时会**自动失效重建**，无需手动清理。
- 写入失败（目录不可写等）不会抛异常，会静默回落到无缓存，不影响解析。
- 缓存文件是运行期数据，**不要提交进 git**（`.gitignore` 已忽略 `*.cache`）。

### 其他方法

```php
AddressParse::clean($text, ['备注']);                    // 只做清洗，返回字符串
AddressParse::setDataPath(storage_path('app/address-parse'));  // 区划数据不在类同级 data 目录时指定
AddressParse::dataPath();                                // 当前使用的数据目录
AddressParse::setCache(true, storage_path('app/addressparse.cache'));  // 开启持久化缓存并指定路径
```

数据目录默认是**类文件同级**的 `data/`。想把区划数据放别处，在 `AppServiceProvider::boot()` 里指定一次即可。

## 数据更新

`data/provinceList.json` 是三级树（省 → 市 → 区），节点只需 `code` 和 `name`：

```json
[{"code": "330000", "name": "浙江省", "children": [
    {"code": "330100", "name": "杭州市", "children": [
        {"code": "330106", "name": "西湖区"}
    ]}
]}]
```

直接替换文件即可更新区划，不用改代码。当前数据为国家地名信息库 2025 年 12 月版。

## 特性

- 双引擎：正则匹配（`type=0`，默认）与行政区划树查找（`type=1`）
- 简称补全：`浙江杭州西湖区` → `浙江省 / 杭州市 / 西湖区`
- 历史区划纠错：`蓟县` → `蓟州区`、`延庆县` → `延庆区`
- 直辖市与省直辖县级市：`市辖区` 归一、`神农架 / 仙桃` 归入区县
- 手机号（含 `+86`、`17951`、`-` 分隔）与邮编提取，6 位门牌号不会误判成邮编
- 姓名识别（严格模式）：仅在有「收货人 / 收件人 / 联系人 / 姓名」标记、紧邻手机号、或带称呼词（先生/小姐/女士/老师/师傅）时输出；否则留空，杜绝把小区/大厦/公司/地名当成姓名

## 相比 Python 原版的三处改进

| 问题 | 原版表现 | 本版处理 |
| --- | --- | --- |
| 树查找同名区县串省 | `徐州市鼓楼区` 被识别成南京鼓楼区（320106） | 先按"市"精确匹配，匹配不到才退回按"省"；省直辖县级市不受影响 |
| 姓名误判 | 815 条真实地址里 55 条把小区/园区/公司名当人名（`烯望家园`、`开发区`、`东方时代7幢1701`） | 严格模式：姓名必须有「收货人/收件人/联系人/姓名」标记、紧邻手机号或称呼词才输出，否则留空（详见「已知取舍」） |
| 复姓长度失控 | 复姓分支没有长度上限，`东方时代7幢1701` 因以"东方"开头被当成人名 | 复姓同样受 `nameMaxLength` 约束 |

代价：与 Python 版不再逐字节一致，仅在上述三类场景下有差异（详见「验证」）。

## 已知取舍

- 只识别 11 位手机号，不识别固定电话
- 姓名默认**严格模式**（宁缺毋滥）：没有「收货人/收件人/联系人/姓名」标记、未紧邻手机号、无称呼词、且不是"整串即姓名"时，`name` 一律留空。因此**无标记的少数民族长名**（如 `阿依古丽`、`买买提江`、`扎西`）可能识别不到；需要高召回时可结合手机号字段自行兜底
- 手机号可能从长数字串（18 位身份证、13 位房号、订单号）中抠出 11 位而误报，量很小
- 邮编要求 6 位数字前后不紧邻其他数字，且不能紧跟 `号 / 室 / 楼` 等门牌号后缀

## 验证

**真实数据**：815 条收货地址（含东莞/中山直管市、苏州工业园区、乡镇村组等）

| 指标 | type0 正则 | type1 树查找 |
| --- | --- | --- |
| 省市区三级齐全 | 765 / 815（93.9%） | 765 / 815（93.9%） |
| 省市区层级错配 | 0 | 0（修复前 1） |
| 姓名误判 | 3 条 | 3 条（修复前 55 条） |
| 平均耗时 | 0.62 ms/条 | 0.19 ms/条 |

**回归对拍**：456 条人工用例（两种模式 × 有无补充区划）与 Python 原版比对

- 非姓名字段差异仅 26 条，全部是预期内的：2 条鼓楼区 code 修正、24 条因姓名不再误判而让详细地址更完整
- 姓名差异 24 条全部是"误判被消除"，无一条真实姓名被漏识别
- 真实姓名正负样本验证：`张三` `李四先生` `王小姐` `欧阳修` `司马光` `阿依古丽` `买买提江` `艾山江` `巴特尔` `扎西` `李海` 均正确识别；`已签收` `幸福小区` `学军小学` `烯望家园` `新馨园` `华都御府` 均正确排除

## 环境

PHP >= 7.4（兼容 8.x），仅需 `ext-json`。装了 `ext-mbstring` 会快一点，没装会自动走 preg 兜底，不报错。

## License

[MIT](LICENSE)
