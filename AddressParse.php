<?php
/**
 * AddressParse - 中国大陆收货地址解析（单文件类）
 *
 * 从一段混杂文本中拆出 省 / 市 / 区 / 详细地址 / 姓名 / 手机号 / 邮编。
 * 移植自 Python 版 address-parse，解析逻辑与原版逐条对齐。
 *
 * 用法（无需 composer 依赖，放进 Laravel 的 app/Services 即可）：
 *
 *     use App\Services\AddressParse;
 *
 *     $result = AddressParse::parse('浙江省杭州市西湖区文三路138号 张三 13800138000');
 *
 *     // 指定解析模式（0 正则 / 1 树查找）
 *     $result = AddressParse::parse($address, ['type' => AddressParse::TYPE_TREE]);
 *
 *     // 区划数据不放同目录时，先指定数据目录
 *     AddressParse::setDataPath(storage_path('app/address-parse'));
 *
 * 返回：
 *     [
 *         'phone' => '13800138000',
 *         'postalCode' => '',
 *         'province' => '浙江省',
 *         'city' => '杭州市',
 *         'area' => '西湖区',
 *         'detail' => '文三路138号',
 *         'name' => '张三',
 *         'provinceCode' => '330000',
 *         'cityCode' => '330100',
 *         'areaCode' => '330106',
 *     ]
 *
 * 区划数据默认读同目录下 data/provinceList.json 与 data/names.json。
 *
 * @version 1.1.0
 * @license MIT
 */

namespace App\Services;

class AddressParse
{
    /** 正则匹配（默认） */
    const TYPE_REGEX = 0;

    /** 行政区划树查找 */
    const TYPE_TREE = 1;

    /** 详细地址片段排序关键词 */
    const ORDER_KEYWORDS = array('省', '市', '区', '县', '镇');

    /** 这些城市名属于"占位"名称，用省份名替代 */
    const PLACEHOLDER_CITY_NAMES = array('市辖区', '区', '县', '镇');

    /** 出现在"残留后缀"之后时，说明该字其实是道路名的一部分（如"东市街"的"市"） */
    const STREET_FOLLOW_CHARS = '街路巷弄道';

    /** 紧跟在这些字符后面的 6 位数字属于门牌号/房间号，不是邮编 */
    const POSTAL_SUFFIX_CHARS = '号室栋楼层幢弄座组队村巷排期';

    /** 预定义清洗关键词 */
    const CLEAN_KEYWORDS = array(
        '详细地址', '收货地址', '收件地址', '地址', '所在地区',
        '姓名', '收货人', '收件人', '联系人',
        '邮编', '联系电话', '电话', '联系人手机号码',
        '手机号码', '手机号',
        '自治区直辖县级行政区划', '省直辖县级行政区划',
        '直辖县级行政区划', '自治区直辖县', '省直辖县',
        '市辖区',
    );

    /** 需要替换为空格的标点符号（等价于 Python 版的字符类） */
    const SPECIAL_CHARS = array(
        '`', '~', '!', '@', '#', '$', '^', '&', '*', '(', ')', '=', '|', '{', '}',
        "'", ':', ';', ',', '[', ']', '.', '<', '>', '/', '?',
        '！', '@', '#', '￥', '…', '&', '*', '（', '）', '—', '|', '【', '】',
        "'", '；', '：', "'", '。', '，', '、', '？',
    );

    /** 直辖市（用于修正"北京北京"这类重复） */
    const MUNICIPALITY = array('北京', '上海', '天津', '重庆');

    /** 称呼词：命中即认定为人名 */
    const NAME_CALLS = array(
        '先生', '小姐', '女士', '男士', '同志', '老师', '师傅',
        '哥哥', '姐姐', '妹妹', '弟弟',
        '妈妈', '爸爸', '爷爷', '奶奶', '姑姑', '舅舅',
    );

    /** 地址关键词：命中即否决人名 */
    const ADDR_FILTERS = array(
        '街道', '乡镇', '镇', '乡', '路', '巷', '弄', '号', '楼',
        '栋', '单元', '室', '座', '层', '门', '队', '组', '村',
        // 常见非人名词尾，避免"幸福小区""学军小学"被当成姓名
        '小区', '大厦', '大楼', '公寓', '花园', '广场', '中心',
        '学校', '小学', '中学', '大学', '学院', '医院', '公司',
        '工厂', '酒店', '宾馆', '超市', '驿站', '物业', '快递',
        '代收', '门卫',
        // 园区 / 小区 / 商业体名称，避免"烯望家园""开发区""科技城"被当成姓名
        '家园', '园区', '公园', '开发区', '工业区', '城区', '新区', '新城',
        '科技城', '产业园', '工业园', '物流园', '时代', '花苑', '新苑', '雅苑',
        '地区', '中国', '生活区',
        // 单字机构 / 地名后缀，中文姓名里几乎不会出现
        '馆', '桥', '苑', '店', '铺', '场', '局', '所', '院', '校',
        '站', '港', '岛', '湾', '塔', '寺', '庙', '堂', '厂', '矿',
        '营', '屯', '庄', '坊', '里', '塘', '坝', '堤', '岸', '滩',
        '区', '府', '头', '面',
    );

    /**
     * 兜底判定专用的地名特征字
     *
     * 只在"片段首字不在百家姓"时生效（这类片段本来就不像汉名），
     * 用来挡住"新馨园""于大桑树"这种小区名 / 村名。
     * 首字是百家姓的片段走 isName 分支，不受这张表影响。
     */
    const FALLBACK_ADDR_CHARS = array(
        '园', '苑', '城', '府', '湾', '里', '庄', '屯', '营', '坊',
        '桥', '站', '场', '馆', '店', '铺', '院', '校', '所', '局',
        '部', '厂', '矿', '塔', '寺', '庙', '堂', '岸', '滩', '洲',
        // 江/山/海 故意不列：维吾尔族常见名"买买提江""艾山""艾海提"含这些字
        '岛', '港', '湖', '河', '头', '面', '树',
        '沟', '塘', '坝', '堤', '坡', '岭', '泉', '溪', '井', '台',
        '集', '街', '道', '巷', '弄', '路', '村', '镇', '乡', '区',
        '县', '市', '州', '旗', '楼', '栋', '幢', '室', '座', '层',
        '门', '号', '组', '队',
    );

    /** 长度同样在 2~4 之间、但显然不是姓名的状态/动作词 */
    const NON_NAME_WORDS = array(
        '签收', '已收', '待收', '拒收', '退回', '派送', '派件',
        '送达', '发货', '收货', '谢谢', '麻烦', '尽快', '务必',
        '备注', '急用', '联系', '到了', '请在', '放门口', '打电话',
        // 占位词：空地址「暂无」不能被当成人名
        '暂无', '无地址', '暂无地址', '没有',
    );

    /** 名称包含该词的城市节点会被扁平化，子节点提升一级 */
    const FLATTEN_CITY_KEYWORDS = array('行政区划');

    /** 持久化缓存的数据结构版本号（改动缓存字段时递增，旧缓存会自动失效重建） */
    const CACHE_VERSION = 1;

    /** @var string|null 区划数据目录（null 表示使用 __DIR__/data） */
    private static $dataPath = null;

    /** @var bool 是否已加载 */
    private static $loaded = false;

    /** @var array 省 / 市 / 区 扁平表 */
    private static $provinces = array();
    private static $cities = array();
    private static $areas = array();

    /** @var string 紧凑 JSON 字符串（正则引擎直接在上面匹配） */
    private static $provinceString = '';
    private static $cityString = '';
    private static $areaString = '';

    /** @var array 百家姓哈希表 name => true */
    private static $nameSet = array();

    /** @var array 省/市/区县名称集合（含去后缀简称），用于排除"江阴""朝阳"这类地名被当成人名 */
    private static $regionNames = array();

    /** @var bool 是否启用持久化文件缓存（跨进程复用已解析的数据） */
    private static $cacheEnabled = false;

    /** @var string|null 缓存文件路径（null 表示 dataPath()/addressparse.cache） */
    private static $cachePath = null;

    /**
     * 指定区划数据目录（默认用本文件同级 data 目录）
     *
     * 数据文件为 provinceList.json 与 names.json。
     *
     * @param  string $path
     * @return void
     */
    public static function setDataPath($path)
    {
        self::$dataPath = rtrim($path, '/\\');
        self::$loaded = false;
    }

    /**
     * 区划数据目录
     *
     * @return string
     */
    public static function dataPath()
    {
        return self::$dataPath !== null && self::$dataPath !== ''
            ? self::$dataPath
            : __DIR__ . DIRECTORY_SEPARATOR . 'data';
    }

    /**
     * 启用/关闭持久化文件缓存
     *
     * PHP-FPM / mod_php 每个请求都是新进程，静态属性只在单次请求内复用；
     * 开启后会把「已解析好的区划数据」写入一个缓存文件，下次请求直接反序列化，
     * 省掉每次读取 + json_decode 142KB + 建索引的开销。缓存文件比源 json 旧时自动重建。
     *
     * 缓存写入失败（目录不可写等）不会抛异常，会静默回落到无缓存。
     *
     * @param  bool        $enabled 是否启用
     * @param  string|null $path    缓存文件的**完整路径（含文件名）**，不是目录；
     *                              例如 /var/cache/addressparse.cache。传 null 用
     *                              dataPath()/addressparse.cache。若误传了目录（已存在
     *                              或以 / \ 结尾），会自动补上文件名 addressparse.cache。
     * @return void
     */
    public static function setCache($enabled = true, $path = null)
    {
        $newEnabled = self::normalizeBool($enabled, self::$cacheEnabled);
        $newPath = $path !== null ? self::resolveCachePath((string) $path) : self::$cachePath;

        if ($newEnabled !== self::$cacheEnabled || $newPath !== self::$cachePath) {
            // 缓存配置变化，需要重新加载才能生效
            self::$loaded = false;
        }
        self::$cacheEnabled = $newEnabled;
        self::$cachePath = $newPath;
    }

    /**
     * 归一化缓存路径：传目录（已存在或以分隔符结尾）时自动补默认文件名，避免把目录当文件用。
     *
     * @param  string $path
     * @return string
     */
    private static function resolveCachePath($path)
    {
        $trimmed = rtrim($path, '/\\');
        $isDir = $path !== $trimmed || ($trimmed !== '' && is_dir($trimmed));

        return ($isDir && $trimmed !== '')
            ? $trimmed . DIRECTORY_SEPARATOR . 'addressparse.cache'
            : $trimmed;
    }

    /**
     * 解析地址
     *
     * @param  string|null $address 待解析文本
     * @param  array       $options type / textFilter / nameMaxLength / extraGovData
     *                              （snake_case 写法同样支持）
     * @return array
     */
    public static function parse($address, array $options = array())
    {
        $empty = array(
            'phone' => '', 'postalCode' => '', 'province' => '', 'city' => '',
            'area' => '', 'detail' => '', 'name' => '',
            'provinceCode' => '', 'cityCode' => '', 'areaCode' => '',
        );

        if ($address === null || $address === '') {
            return $empty;
        }

        // 缓存选项必须在 load() 之前应用
        $cacheOpt = isset($options['cache']) ? $options['cache'] : null;
        $cachePathOpt = isset($options['cachePath']) ? $options['cachePath']
            : (isset($options['cache_path']) ? $options['cache_path'] : null);
        if ($cacheOpt !== null || $cachePathOpt !== null) {
            self::setCache(
                $cacheOpt === null ? self::$cacheEnabled : $cacheOpt,
                (is_string($cachePathOpt) && $cachePathOpt !== '') ? $cachePathOpt : null
            );
        }

        self::load();

        $parseType = self::normalizeInt(isset($options['type']) ? $options['type'] : 0, 0);
        $nameMaxLength = self::normalizeInt(
            isset($options['nameMaxLength']) ? $options['nameMaxLength']
                : (isset($options['name_max_length']) ? $options['name_max_length'] : 4),
            4
        );

        $textFilter = isset($options['textFilter']) ? $options['textFilter']
            : (isset($options['text_filter']) ? $options['text_filter'] : array());
        if (!is_array($textFilter)) {
            $textFilter = array();
        }
        $textFilter = array_map('strval', array_values($textFilter));

        $extraGovData = isset($options['extraGovData']) ? $options['extraGovData']
            : (isset($options['extra_gov_data']) ? $options['extra_gov_data'] : null);
        if (!is_array($extraGovData)) {
            $extraGovData = null;
        }

        // 本次解析使用的数据集（extraGovData 只在副本上合并，不影响后续调用）
        $gov = self::dataSet($extraGovData);

        $state = array(
            'province' => array(),
            'city' => array(),
            'area' => array(),
            'detail' => array(),
            'name' => '',
            'phone' => '',
            'postalCode' => '',
            'provinceCode' => '',
            'cityCode' => '',
            'areaCode' => '',
        );

        // 清洗地址
        $address = self::clean($address, $textFilter);

        // 保留"清洗后、去手机号前"的原文，供姓名严格模式判断信号（收货人标记 / 紧邻手机号）
        $rawClean = $address;

        // 提取手机号
        $extracted = self::extractPhone($address);
        $address = $extracted[0];
        $state['phone'] = $extracted[1];

        // 提取邮编
        $extracted = self::extractPostalCode($address);
        $address = $extracted[0];
        $state['postalCode'] = $extracted[1];

        // 地址分割并排序
        $fragments = self::splitAddress($address);

        // 逐片段解析省市区
        foreach ($fragments as $fragment) {
            $needParse = count($state['province']) === 0 || empty($state['province'][0])
                || count($state['city']) === 0 || empty($state['city'][0])
                || count($state['area']) === 0 || empty($state['area'][0]);

            if (!$needParse) {
                $state['detail'][] = $fragment;
                continue;
            }

            $parsed = $parseType === self::TYPE_TREE
                ? self::parseRegionTree($fragment, $state, $gov)
                : self::parseRegionRegexp($fragment, $state, $gov);

            $state['province'] = !empty($parsed['province']) ? $parsed['province'] : $state['province'];
            $state['area'] = !empty($parsed['area']) ? $parsed['area'] : $state['area'];
            $state['city'] = !empty($parsed['city']) ? $parsed['city'] : $state['city'];

            foreach ($parsed['detail'] as $detailFragment) {
                $state['detail'][] = $detailFragment;
            }

            $state['areaCode'] = !empty($state['area']) ? (string) $state['area'][0]['code'] : '';
            $state['provinceCode'] = !empty($state['province']) ? (string) $state['province'][0]['code'] : '';
            $state['cityCode'] = !empty($state['city']) ? (string) $state['city'][0]['code'] : '';
        }

        $provinceObj = !empty($state['province']) ? $state['province'][0] : null;
        $cityObj = !empty($state['city']) ? $state['city'][0] : null;
        $areaObj = !empty($state['area']) ? $state['area'][0] : null;
        $detailList = $state['detail'];

        // 从详细地址中去掉已识别的省市区名称（同一片段上累积替换）
        $recognizedNames = array();
        foreach (array($provinceObj, $cityObj, $areaObj) as $regionObj) {
            if (!empty($regionObj['name'])) {
                $recognizedNames[] = (string) $regionObj['name'];
            }
        }
        foreach ($detailList as $index => $fragment) {
            foreach ($recognizedNames as $regionName) {
                $fragment = str_replace($regionName, '', $fragment);
            }
            $detailList[$index] = $fragment;
        }

        // 保序去重
        $detailList = self::uniqueList($detailList);

        // 从详细地址中识别姓名
        if (count($detailList) > 0) {
            $name = self::detectName($detailList, $nameMaxLength, $gov, $rawClean, $state['phone']);

            if ($name !== '') {
                $state['name'] = $name;
                $kept = array();
                foreach ($detailList as $item) {
                    if ($item !== $name) {
                        $kept[] = $item;
                    }
                }
                $detailList = $kept;
            }
        }

        // 处理城市名称（直辖市的"市辖区"等特殊名称用省份名替代）
        $provinceName = $provinceObj !== null ? (string) $provinceObj['name'] : '';
        $cityName = $cityObj !== null ? (string) $cityObj['name'] : '';
        if (in_array($cityName, self::PLACEHOLDER_CITY_NAMES, true)) {
            $cityName = $provinceName;
        }

        // 县级单位判定：city 匹配后 area 为空
        // 省直辖县级（code 含 90）：移到 area，city 置空
        // 地级市直管（东莞/中山/嘉峪关等）：保留在 city
        if ($cityObj !== null && $areaObj === null) {
            foreach ($gov['areas'] as $candidateArea) {
                if ((string) $candidateArea['code'] === (string) $cityObj['code']
                    && (string) $candidateArea['name'] === (string) $cityObj['name']
                ) {
                    if (substr((string) $cityObj['code'], 2, 2) === '90') {
                        // 省直辖县级（如神农架 429021、仙桃 429004）
                        $areaObj = $candidateArea;
                        $cityObj = null;
                        $cityName = '';
                        $state['areaCode'] = $state['cityCode'];
                        $state['cityCode'] = '';
                    }
                    break;
                }
            }
        }

        // 地级市直管：area 和 city 同名且同 code → 清除 area
        if ($cityObj !== null && $areaObj !== null
            && (string) $areaObj['code'] === (string) $cityObj['code']
        ) {
            if (substr((string) $cityObj['code'], 2, 2) !== '90') {
                $areaObj = null;
                $state['areaCode'] = '';
            }
        }

        return array(
            'phone' => $state['phone'],
            'postalCode' => $state['postalCode'],
            'province' => $provinceName,
            'city' => $cityName,
            'area' => $areaObj !== null ? (string) $areaObj['name'] : '',
            'detail' => implode('', $detailList),
            'name' => $state['name'],
            'provinceCode' => $state['provinceCode'],
            'cityCode' => $state['cityCode'],
            'areaCode' => $state['areaCode'],
        );
    }

    /**
     * 只做地址清洗（去标签、符号、多余空格）
     *
     * @param  string $address
     * @param  array  $textFilter 自定义预清洗词
     * @return string
     */
    public static function clean($address, array $textFilter = array())
    {
        // 去换行和制表符
        $address = str_replace(array("\r\n", "\n", "\t"), ' ', $address);

        // 全角空格 / 不换行空格 / 其他 Unicode 空白 → 半角空格
        // （用户粘贴的地址常见「丰　县」这类全角空格，不归一会导致区县无法匹配）
        $address = (string) preg_replace('/[\x{3000}\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', ' ', $address);

        // 去除预定义关键字和自定义过滤词
        $filters = array_merge(self::CLEAN_KEYWORDS, $textFilter);
        foreach ($filters as $keyword) {
            $keyword = (string) $keyword;
            if ($keyword !== '') {
                $address = str_replace($keyword, ' ', $address);
            }
        }

        // 去除"收：""寄："这类字段标签（保留 回收 / 代收 等正常用字）
        $address = (string) preg_replace('/(?:^|\s)[收寄发]\s*[:：]\s*/u', ' ', $address);

        // 非数字间的 - 替换为空格（保留 1-1-2406 之类房号）
        $address = (string) preg_replace('/(?<!\d)-(?!\d)/u', ' ', $address);

        // 去除特殊符号
        $address = str_replace(self::SPECIAL_CHARS, ' ', $address);

        // 合并多个空格
        $address = (string) preg_replace('/ {2,}/', ' ', $address);

        // 修正直辖市重复名称（如 "北京北京" → "北京"）
        foreach (self::MUNICIPALITY as $municipality) {
            $address = str_replace($municipality . $municipality, $municipality, $address);
        }

        return trim($address);
    }

    // ============================================================
    // 数据加载
    // ============================================================

    /**
     * 加载区划数据（每个进程只读一次）
     *
     * 开启缓存时：先读缓存文件；缺失/过期则加锁重建，避免多 worker 同时重建（重复 CPU）。
     */
    private static function load()
    {
        if (self::$loaded) {
            return;
        }

        if (!self::$cacheEnabled) {
            self::buildData();
            self::$loaded = true;
            return;
        }

        if (self::readCache()) {
            self::$loaded = true;
            return;
        }

        // 缓存缺失/过期：加锁后双重检查，保证同一时刻只有一个进程重建
        $lock = self::acquireLock();
        if ($lock !== false) {
            if (self::readCache()) {
                // 等锁期间别的进程已经重建好了，直接用
                self::releaseLock($lock);
                self::$loaded = true;
                return;
            }
            self::buildData();
            self::$loaded = true;
            self::writeCache();
            self::releaseLock($lock);
            return;
        }

        // 拿不到锁（如缓存目录不可写）：照常构建，保证解析正确，只是不写缓存
        self::buildData();
        self::$loaded = true;
    }

    /**
     * 从 json 读取并构建全部内存数据（不设置 loaded、不写缓存）
     */
    private static function buildData()
    {
        $tree = self::readJson(self::dataPath() . DIRECTORY_SEPARATOR . 'provinceList.json');
        $names = self::readJson(self::dataPath() . DIRECTORY_SEPARATOR . 'names.json');

        // 过滤特殊行政区划：名称含"行政区划"的城市节点扁平化，子节点提升
        foreach ($tree as $provinceIndex => $province) {
            if (empty($province['children'])) {
                continue;
            }

            $childrenToAdd = array();
            $indicesToRemove = array();

            foreach ($province['children'] as $cityIndex => $city) {
                foreach (self::FLATTEN_CITY_KEYWORDS as $keyword) {
                    if (isset($city['name']) && strpos($city['name'], $keyword) !== false) {
                        if (!empty($city['children'])) {
                            foreach ($city['children'] as $child) {
                                $childrenToAdd[] = $child;
                            }
                        }
                        $indicesToRemove[] = $cityIndex;
                    }
                }
            }

            rsort($indicesToRemove);
            foreach ($indicesToRemove as $index) {
                array_splice($tree[$provinceIndex]['children'], $index, 1);
            }
            foreach ($childrenToAdd as $child) {
                $tree[$provinceIndex]['children'][] = $child;
            }
        }

        $provinces = array();
        $cities = array();
        $areas = array();

        foreach ($tree as $province) {
            $provinces[] = array(
                'code' => (string) (isset($province['code']) ? $province['code'] : ''),
                'name' => (string) (isset($province['name']) ? $province['name'] : ''),
            );
        }

        foreach ($tree as $province) {
            $provinceCode = (string) (isset($province['code']) ? $province['code'] : '');
            if (empty($province['children'])) {
                continue;
            }
            foreach ($province['children'] as $city) {
                $cities[] = array(
                    'code' => (string) (isset($city['code']) ? $city['code'] : ''),
                    'name' => (string) (isset($city['name']) ? $city['name'] : ''),
                    'provinceCode' => $provinceCode,
                );
            }
        }

        foreach ($tree as $province) {
            $provinceCode = (string) (isset($province['code']) ? $province['code'] : '');
            if (empty($province['children'])) {
                continue;
            }
            foreach ($province['children'] as $city) {
                $cityCode = (string) (isset($city['code']) ? $city['code'] : '');
                if (empty($city['children'])) {
                    continue;
                }
                foreach ($city['children'] as $area) {
                    $areas[] = array(
                        'code' => (string) (isset($area['code']) ? $area['code'] : ''),
                        'name' => (string) (isset($area['name']) ? $area['name'] : ''),
                        'cityCode' => $cityCode,
                        'provinceCode' => $provinceCode,
                    );
                }
            }
        }

        self::$provinces = $provinces;
        self::$cities = $cities;
        self::$areas = $areas;
        self::$provinceString = self::compactJson($provinces);
        self::$cityString = self::compactJson($cities);
        self::$areaString = self::compactJson($areas);

        self::$nameSet = array();
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                self::$nameSet[$name] = true;
            }
        }

        self::$regionNames = array();
        foreach (array_merge($provinces, $cities, $areas) as $region) {
            if (!empty($region['name'])) {
                self::$regionNames = self::addRegionName(self::$regionNames, (string) $region['name']);
            }
        }

        // 补充省级简称（去民族名 + 后缀）：广西壮族自治区→广西、新疆维吾尔自治区→新疆、宁夏回族自治区→宁夏。
        // 只用于姓名排除——避免「广西/新疆/宁夏」这类简称被当成姓名（尤其紧跟手机号的重复地址片段）。
        static $provSuffixes = array('特别行政区', '自治区', '省', '市');
        static $provEthnic = array('维吾尔', '壮', '回');
        foreach ($provinces as $province) {
            $nm = (string) $province['name'];
            foreach ($provSuffixes as $sfx) {
                if (self::endsWith($nm, $sfx) && self::charLen($nm) > self::charLen($sfx)) {
                    $nm = self::subStr($nm, 0, self::charLen($nm) - self::charLen($sfx));
                    break;
                }
            }
            foreach ($provEthnic as $eth) {
                if (self::endsWith($nm, $eth . '族')) {
                    $nm = self::subStr($nm, 0, self::charLen($nm) - self::charLen($eth . '族'));
                    break;
                }
            }
            if ($nm !== '' && $nm !== (string) $province['name']) {
                self::$regionNames[$nm] = true;
            }
        }

    }

    /**
     * 缓存文件路径
     *
     * @return string
     */
    private static function cacheFile()
    {
        if (self::$cachePath !== null && self::$cachePath !== '') {
            return self::$cachePath;
        }

        return self::dataPath() . DIRECTORY_SEPARATOR . 'addressparse.cache';
    }

    /**
     * 尝试从缓存文件恢复已解析数据；成功返回 true
     *
     * @return bool
     */
    private static function readCache()
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return false;
        }

        // 任一源 json 比缓存新，视为过期
        $cacheTime = @filemtime($file);
        $srcTime = max(
            @filemtime(self::dataPath() . DIRECTORY_SEPARATOR . 'provinceList.json'),
            @filemtime(self::dataPath() . DIRECTORY_SEPARATOR . 'names.json')
        );
        if ($cacheTime === false || $srcTime === false || $cacheTime < $srcTime) {
            return false;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return false;
        }

        $data = @unserialize($raw);
        if (!is_array($data) || !isset($data['v']) || $data['v'] !== self::CACHE_VERSION) {
            return false;
        }

        $keys = array('provinces', 'cities', 'areas', 'provinceString', 'cityString', 'areaString', 'nameSet', 'regionNames');
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }

        self::$provinces = $data['provinces'];
        self::$cities = $data['cities'];
        self::$areas = $data['areas'];
        self::$provinceString = $data['provinceString'];
        self::$cityString = $data['cityString'];
        self::$areaString = $data['areaString'];
        self::$nameSet = $data['nameSet'];
        self::$regionNames = $data['regionNames'];

        return true;
    }

    /**
     * 把已解析数据写入缓存文件（失败静默忽略，不影响正常解析）
     *
     * @return void
     */
    private static function writeCache()
    {
        $data = array(
            'v' => self::CACHE_VERSION,
            'provinces' => self::$provinces,
            'cities' => self::$cities,
            'areas' => self::$areas,
            'provinceString' => self::$provinceString,
            'cityString' => self::$cityString,
            'areaString' => self::$areaString,
            'nameSet' => self::$nameSet,
            'regionNames' => self::$regionNames,
        );

        $raw = serialize($data);
        $file = self::cacheFile();

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // 原子写入：先写临时文件再 rename，避免并发读到半截内容
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * 获取重建缓存用的排他锁（有界等待，拿不到返回 false）
     *
     * 用独立 .lock 文件，避免多 worker 同时重建缓存。等待最多 2 秒：正常情况下
     * 别的进程构建只需百毫秒级；超时或锁文件不可创建时返回 false，调用方会直接构建，
     * 保证解析正确且绝不卡死。
     *
     * @return resource|false
     */
    private static function acquireLock()
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $handle = @fopen($file . '.lock', 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + 2.0;
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            if (microtime(true) >= $deadline) {
                @fclose($handle);
                return false;
            }
            usleep(10000); // 10ms 后再试
        }
    }

    /**
     * 释放重建缓存用的锁
     *
     * @param  resource|false $handle
     * @return void
     */
    private static function releaseLock($handle)
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * 本次解析使用的数据集；带 extraGovData 时在副本上合并
     *
     * @param  array|null $extraGovData
     * @return array
     */
    private static function dataSet($extraGovData)
    {
        $base = array(
            'provinces' => self::$provinces,
            'cities' => self::$cities,
            'areas' => self::$areas,
            'provinceString' => self::$provinceString,
            'cityString' => self::$cityString,
            'areaString' => self::$areaString,
            'regionNames' => self::$regionNames,
        );

        if (empty($extraGovData)) {
            return $base;
        }

        $extraProvince = isset($extraGovData['province']) ? $extraGovData['province'] : array();
        $extraCity = isset($extraGovData['city']) ? $extraGovData['city'] : array();
        $extraArea = isset($extraGovData['area']) ? $extraGovData['area'] : array();

        if (empty($extraProvince) && empty($extraCity) && empty($extraArea)) {
            return $base;
        }

        $base['provinces'] = array_merge(self::$provinces, array_values($extraProvince));
        $base['cities'] = array_merge(self::$cities, array_values($extraCity));
        $base['areas'] = array_merge(self::$areas, array_values($extraArea));
        $base['provinceString'] = self::compactJson($base['provinces']);
        $base['cityString'] = self::compactJson($base['cities']);
        $base['areaString'] = self::compactJson($base['areas']);

        // 补充区划的名称同样要参与"是不是地名"的判定
        foreach (array_merge($base['provinces'], $base['cities'], $base['areas']) as $region) {
            if (!empty($region['name'])) {
                $base['regionNames'] = self::addRegionName($base['regionNames'], (string) $region['name']);
            }
        }

        return $base;
    }

    /**
     * 把区划名登记进集合，同时登记去掉行政后缀的简称
     *
     * "江阴市" → 同时登记 "江阴市" 与 "江阴"，这样地址里只写简称时也能认出是地名。
     *
     * @param  array  $set
     * @param  string $name
     * @return array
     */
    private static function addRegionName(array $set, $name)
    {
        $set[$name] = true;

        static $suffixes = array(
            '特别行政区', '自治区', '自治州', '自治县', '自治旗', '地区', '新区',
            '省', '市', '区', '县', '旗', '盟', '州', '镇', '乡',
        );

        $length = self::charLen($name);
        foreach ($suffixes as $suffix) {
            $suffixLength = self::charLen($suffix);
            if ($length > $suffixLength + 1 && self::endsWith($name, $suffix)) {
                $set[self::subStr($name, 0, $length - $suffixLength)] = true;
                break;
            }
        }

        return $set;
    }

    /**
     * 紧凑 JSON（无空格，键序保持 code → name → provinceCode → cityCode）
     *
     * 正则引擎依赖这个格式，改动会导致匹配失效。
     */
    private static function compactJson($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    /**
     * @param  string $path
     * @return array
     */
    private static function readJson($path)
    {
        if (!is_file($path)) {
            throw new RuntimeException('AddressParse 区划数据文件不存在：' . $path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('AddressParse 区划数据文件读取失败：' . $path);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AddressParse 区划数据文件格式错误：' . $path);
        }

        return $decoded;
    }

    // ============================================================
    // 手机号 / 邮编提取
    // ============================================================

    /**
     * @param  string $address
     * @return array [去掉手机号后的地址, 手机号]
     */
    private static function extractPhone($address)
    {
        // 整理电话格式：138-0013-8000 / 138 0013 8000 → 13800138000
        $address = (string) preg_replace('/(\d{3})-(\d{4})-(\d{4})/u', '$1$2$3', $address);
        $address = (string) preg_replace('/(\d{3}) (\d{4}) (\d{4})/u', '$1$2$3', $address);

        $phone = '';
        if (preg_match('/(?:0|\+?86-?|17951)?1[3456789]\d{9}(?:-\d{4})?/u', $address, $match)) {
            $phone = $match[0];
            $address = self::replaceFirst($match[0], ' ', $address);
        }

        // 去除国际区号前缀
        $phone = (string) preg_replace('/^\+?86-?/u', '', $phone);

        return array($address, $phone);
    }

    /**
     * 仅当 6 位数字处于"非门牌号"上下文时才认定为邮编，
     * 否则"文三路123456号"这类门牌号会被误当成邮编摘走并破坏详细地址。
     *
     * @param  string $address
     * @return array [去掉邮编后的地址, 邮编]
     */
    private static function extractPostalCode($address)
    {
        if (!preg_match_all('/(?<!\d)[1-9]\d{5}(?!\d)/u', $address, $matches, PREG_OFFSET_CAPTURE)) {
            return array($address, '');
        }

        $length = strlen($address);

        foreach ($matches[0] as $item) {
            $code = $item[0];
            $start = $item[1];
            $end = $start + strlen($code);

            if ($end < $length) {
                $nextChar = self::firstChar(substr($address, $end));
                if ($nextChar !== '' && self::contains(self::POSTAL_SUFFIX_CHARS, $nextChar)) {
                    // 形如 123456号 / 100022室 → 门牌号、房间号
                    continue;
                }
            }

            if ($start > 0) {
                $prevChar = self::lastChar(substr($address, 0, $start));
                if ($prevChar !== '' && self::contains(self::POSTAL_SUFFIX_CHARS, $prevChar)) {
                    continue;
                }
            }

            return array(
                substr($address, 0, $start) . ' ' . substr($address, $end),
                $code,
            );
        }

        return array($address, '');
    }

    // ============================================================
    // 姓名识别
    // ============================================================

    /**
     * 片段是否具备"可能是姓名"的基本形态
     *
     * 三条硬性否决：
     *  - 不含中文
     *  - 含数字（门牌号，如"东方时代7幢1701"）
     *  - 等于某个省 / 市 / 区县名（如"江阴""朝阳"这类地名）
     *
     * @param  string $fragment
     * @param  array  $gov
     * @return bool
     */
    private static function isNameShape($fragment, array $gov)
    {
        if ($fragment === '' || !preg_match('/[\x{4E00}-\x{9FA5}]/u', $fragment)) {
            return false;
        }

        if (preg_match('/\d/u', $fragment)) {
            return false;
        }

        if (isset($gov['regionNames'][$fragment])) {
            return false;
        }

        return true;
    }

    /**
     * @param  string $fragment
     * @param  int    $nameMaxLength
     * @param  array  $gov
     * @return bool
     */
    private static function isName($fragment, $nameMaxLength, array $gov)
    {
        if (!self::isNameShape($fragment, $gov)) {
            return false;
        }

        // 包含地址关键词 → 不是人名（必须先判，否则"妈妈驿站"会被称呼词捞走）
        foreach (self::ADDR_FILTERS as $filter) {
            if (self::contains($fragment, $filter)) {
                return false;
            }
        }

        // 状态/动作词（谢谢、麻烦、到了、自取…）即使首字是姓氏也不当人名
        foreach (self::NON_NAME_WORDS as $word) {
            if (self::contains($fragment, $word)) {
                return false;
            }
        }

        $length = self::charLen($fragment);

        // 两字叠词（谢谢/慢慢/多多）不是姓名
        if ($length === 2 && self::firstChar($fragment) === self::subStr($fragment, 1, 1)) {
            return false;
        }

        // 包含称呼词 → 是人名（"李四先生"；长度上限防止整句被当成姓名）
        foreach (self::NAME_CALLS as $call) {
            if (self::contains($fragment, $call) && $length <= $nameMaxLength + 2) {
                return true;
            }
        }
        if ($length > 1 && $length <= $nameMaxLength && isset(self::$nameSet[self::firstChar($fragment)])) {
            return true;
        }

        // 检查复姓（同样受 nameMaxLength 约束，否则"东方时代7幢"这类长串会被当成复姓名）
        if ($length > 2 && $length <= $nameMaxLength && isset(self::$nameSet[self::subStr($fragment, 0, 2)])) {
            return true;
        }

        return false;
    }

    /**
     * 兜底判定：至少 2 个字、含中文、不含地址关键词，也不是"已签收"这类状态词
     *
     * 注意不能要求首字是常见姓氏：少数民族姓名（如"阿依古丽"）会被误杀
     *
     * @param  string $fragment
     * @param  int    $nameMaxLength
     * @param  array  $gov
     * @return bool
     */
    private static function isFallbackName($fragment, $nameMaxLength, array $gov)
    {
        $length = self::charLen($fragment);
        if ($length < 2 || $length > $nameMaxLength) {
            return false;
        }

        if (!self::isNameShape($fragment, $gov)) {
            return false;
        }

        foreach (self::ADDR_FILTERS as $filter) {
            if (self::contains($fragment, $filter)) {
                return false;
            }
        }

        foreach (self::NON_NAME_WORDS as $word) {
            if (self::contains($fragment, $word)) {
                return false;
            }
        }

        // 首字不是百家姓的片段，只要含地名 / 机构特征字就不当姓名
        if (!isset(self::$nameSet[self::firstChar($fragment)])) {
            foreach (self::FALLBACK_ADDR_CHARS as $char) {
                if (self::contains($fragment, $char)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 在详细地址片段中挑出姓名（优先取最短的候选片段）
     *
     * @param  array $details
     * @param  int   $nameMaxLength
     * @param  array $gov
     * @return string
     */
    private static function detectName(array $details, $nameMaxLength, array $gov, $rawClean = '', $phone = '')
    {
        if (count($details) === 0) {
            return '';
        }

        $sorted = self::sortByLength($details);

        foreach ($sorted as $fragment) {
            if (!self::isName($fragment, $nameMaxLength, $gov)) {
                continue;
            }

            // 严格模式：只有拿到可信信号才认定为人名，杜绝把小区/大厦/公司/地名硬当姓名。
            //  1) 收货人 / 收件人 / 联系人 / 姓名 标记
            //  2) 姓名紧邻手机号（含「手机号：」「电话：」等）
            //  3) 先生 / 小姐 / 女士 / 老师 / 师傅 等称呼词
            //  4) 结尾独立姓名（…，陈实霜 / …（刘飞） / 裸"张三"）
            if (self::nameHasSignal($fragment, $rawClean, $phone)
                || self::hasNameTitle($fragment)
            ) {
                return $fragment;
            }
        }

        return '';
    }

    /** 严格模式：姓名是否带可信信号（收货人标记 / 紧邻手机号 / 结尾独立姓名） */
    private static function nameHasSignal($name, $rawClean, $phone)
    {
        if ($rawClean === '' || $name === '') {
            return false;
        }

        $q = preg_quote($name, '/');

        // 1) 收货人 / 收件人 / 联系人 / 姓名 标记，后接姓名
        if (preg_match('/(收货人|收件人|联系人|联系人姓名|收货姓名|姓名)\s*[:：]?\s*' . $q . '/u', $rawClean)) {
            return true;
        }

        if ($phone !== '') {
            $qp = preg_quote($phone, '/');
            // 2a) 姓名（可带"手机号/电话"等）紧接手机号
            if (preg_match('/' . $q . '\s*(手机号码|手机号|手机|电话|联系方式|微信|联系)?\s*[:：]?\s*' . $qp . '/u', $rawClean)) {
                return true;
            }
            // 2b) 手机号后紧跟姓名
            if (preg_match('/' . $qp . '\s*[:：]?\s*' . $q . '/u', $rawClean)) {
                return true;
            }
            // 2c) 通用窗口（姓名在手机号附近）
            $idx = self::charIndex($rawClean, $phone, 0);
            if ($idx !== -1) {
                $nl = self::charLen($name);
                $start = $idx - $nl - 4;
                if ($start < 0) {
                    $start = 0;
                }
                $win = self::subStr($rawClean, $start, self::charLen($phone) + 2 * ($nl + 4));
                if (self::contains($win, $name)) {
                    return true;
                }
            }
        }

        // 3) 结尾独立姓名：去掉尾部标点后以该姓名结尾，且前面是分隔符或空
        $tail = $rawClean;
        $trailPunct = array('，', ',', '。', '.', '；', ';', '）', ')', '】', ']', '》', '>', ' ', '　');
        while (self::charLen($tail) > 0) {
            $last = self::subStr($tail, self::charLen($tail) - 1, 1);
            if (in_array($last, $trailPunct, true)) {
                $tail = self::subStr($tail, 0, self::charLen($tail) - 1);
            } else {
                break;
            }
        }
        if ($tail !== '' && self::endsWith($tail, $name)) {
            $before = '';
            if (self::charLen($tail) > self::charLen($name)) {
                $before = self::subStr($tail, self::charLen($tail) - self::charLen($name) - 1, 1);
            }
            // 整串就是一个姓名（before 为空）才认；空格分隔不认——clean() 会把「，（）」折成空格，
            // 若放行空格，会把「…某小区 高岭坑」「…华山镇 安诚口腔」这类地名/机构名误收成姓名。
            if ($before === '' || in_array($before, array('，', ',', '（', '(', '、', '/', '；', ';', ':', '：'), true)) {
                return true;
            }
        }

        return false;
    }

    /** 是否含称呼词（先生/小姐/女士/老师/师傅…）——强人名信号 */
    private static function hasNameTitle($fragment)
    {
        foreach (self::NAME_CALLS as $call) {
            if (self::contains($fragment, $call)) {
                return true;
            }
        }
        return false;
    }

    // ============================================================
    // 正则匹配解析（type = 0）
    // ============================================================

    /**
     * 在紧凑 JSON 字符串上做前缀匹配：把"候选片段前缀"拼进正则，
     * 命中说明该前缀是某个省 / 市 / 区的名称前缀，再借助 code 归属反推上下级。
     *
     * @param  string $fragment
     * @param  array  $hasParseResult
     * @param  array  $gov
     * @return array
     */
    private static function parseRegionRegexp($fragment, array $hasParseResult, array $gov)
    {
        $province = array_values(isset($hasParseResult['province']) ? $hasParseResult['province'] : array());
        $city = array_values(isset($hasParseResult['city']) ? $hasParseResult['city'] : array());
        $area = array_values(isset($hasParseResult['area']) ? $hasParseResult['area'] : array());
        $detail = array();

        $matchStr = '';

        // 匹配省级
        if (count($province) === 0) {
            $length = self::charLen($fragment);
            for ($i = 1; $i < $length; $i++) {
                $candidate = self::subStr($fragment, 0, $i + 1);
                $pattern = '/\{"code":"[0-9]{1,6}","name":"' . preg_quote($candidate, '/')
                    . '[\x{4E00}-\x{9FA5}]*?"\}/u';
                $count = preg_match_all($pattern, $gov['provinceString'], $matches);
                if ($count) {
                    $obj = json_decode($matches[0][0], true);
                    if ($count === 1) {
                        $province = array($obj);
                        $matchStr = $candidate;
                    }
                } else {
                    break;
                }
            }

            if (count($province) > 0 && !empty($province[0])) {
                if ($matchStr !== '') {
                    $fragment = self::replaceFirst($matchStr, '', $fragment);
                }
            }
        }

        // 匹配市级
        if (count($city) === 0) {
            $fragmentBeforeCity = $fragment;
            $fragment = self::stripLeadingAdminNoise($fragment);

            $provinceCode = (count($province) > 0 && !empty($province[0]))
                ? (string) $province[0]['code']
                : '[0-9]{1,6}';

            $length = self::charLen($fragment);
            for ($i = 1; $i < $length; $i++) {
                $candidate = self::subStr($fragment, 0, $i + 1);
                $pattern = '/\{"code":"[0-9]{1,6}","name":"' . preg_quote($candidate, '/')
                    . '[\x{4E00}-\x{9FA5}]*?","provinceCode":"' . $provinceCode . '"\}/u';
                $count = preg_match_all($pattern, $gov['cityString'], $matches);
                if ($count) {
                    $obj = json_decode($matches[0][0], true);
                    if ($count === 1) {
                        $city = array($obj);
                        $matchStr = $candidate;
                    }
                } else {
                    break;
                }
            }

            if (count($city) > 0 && !empty($city[0])) {
                if ($matchStr !== '') {
                    $fragment = self::replaceFirst($matchStr, '', $fragment);
                }

                // 如果没匹配到省，通过市的 provinceCode 反推
                if (count($province) === 0) {
                    $province = self::findProvinceByCode((string) $city[0]['provinceCode'], $gov);
                }
            }

            // 剥离后仍没匹配到市，恢复原片段（避免吞掉正文）
            if (count($city) === 0) {
                $fragment = $fragmentBeforeCity;
            }
        }

        // 匹配区级
        if (count($area) === 0) {
            $fragmentBeforeArea = $fragment;
            $fragment = self::stripLeadingAdminNoise($fragment);

            $provinceCode = (count($province) > 0 && !empty($province[0]))
                ? (string) $province[0]['code']
                : '[0-9]{1,6}';
            $cityCode = (count($city) > 0 && !empty($city[0]))
                ? (string) $city[0]['code']
                : '[0-9]{1,6}';

            $length = self::charLen($fragment);
            for ($i = 1; $i < $length; $i++) {
                $candidate = self::subStr($fragment, 0, $i + 1);
                $pattern = '/\{"code":"[0-9]{1,9}","name":"' . preg_quote($candidate, '/')
                    . '[\x{4E00}-\x{9FA5}]*?","cityCode":"' . $cityCode
                    . '","provinceCode":"' . $provinceCode . '"\}/u';
                $count = preg_match_all($pattern, $gov['areaString'], $matches);
                if ($count) {
                    $obj = json_decode($matches[0][0], true);
                    if ($count === 1) {
                        $area = array($obj);
                        $matchStr = $candidate;
                    }
                } else {
                    break;
                }
            }

            if (count($area) > 0 && !empty($area[0])) {
                if ($matchStr !== '') {
                    $fragment = self::replaceFirst($matchStr, '', $fragment);
                }

                // 旧名→新名：延庆县 → 延庆区，去掉残留的"县"
                $fragment = self::stripLegacyDistrictSuffix($fragment, $matchStr, (string) $area[0]['name']);

                // 反推市和省
                if (count($city) === 0) {
                    $city = self::findCityByCode(
                        (string) $area[0]['cityCode'],
                        (string) $area[0]['provinceCode'],
                        $gov
                    );
                }
                if (count($province) === 0) {
                    $province = self::findProvinceByCode((string) $area[0]['provinceCode'], $gov);
                }
            }

            // 容错：县→区（如蓟县→蓟州区、延庆县→延庆区）
            // 仅在已知省/市时启用：否则片段可能是未识别正文，全局匹配会串到外省同名区（资阳→资阳区）
            if (count($area) === 0 && (count($province) > 0 || count($city) > 0)
                && (self::contains($fragment, '县') || self::contains($fragment, '市'))
            ) {
                foreach (array(array('县', '区'), array('市', '区')) as $suffixPair) {
                    $oldSuffix = $suffixPair[0];
                    $newSuffix = $suffixPair[1];

                    $index = self::charIndex($fragment, $oldSuffix, 1);
                    if ($index === -1) {
                        continue;
                    }

                    $prefix = self::subStr($fragment, 0, $index);
                    $prefixLength = self::charLen($prefix);

                    for ($i = $prefixLength; $i > 0; $i--) {
                        // 区名干必须紧贴 县/市 后缀；前缀多于一个字时，不接受单字候选
                        if ($i === 1 && $prefixLength > 1) {
                            continue;
                        }
                        $candidate = self::subStr($prefix, $prefixLength - $i, $i);
                        $pattern = '/\{"code":"[0-9]{1,9}","name":"' . preg_quote($candidate, '/')
                            . '[\x{4E00}-\x{9FA5}]*?' . $newSuffix
                            . '","cityCode":"' . $cityCode
                            . '","provinceCode":"' . $provinceCode . '"\}/u';
                        $count = preg_match_all($pattern, $gov['areaString'], $matches);
                        if ($count === 1) {
                            $area = array(json_decode($matches[0][0], true));
                            $fragment = (string) $area[0]['name'] . self::subStr($fragment, $index + 1);
                            break;
                        }
                        // $count > 1 时继续缩短候选前缀
                    }

                    if (count($area) > 0) {
                        break;
                    }
                }
            }

            // 剥离后仍没匹配到区，恢复原片段（保留完整正文）
            if (count($area) === 0) {
                $fragment = $fragmentBeforeArea;
            }
        }

        if (trim($fragment) !== '') {
            $detail[] = trim($fragment);
        }

        return array(
            'province' => $province,
            'city' => $city,
            'area' => $area,
            'detail' => $detail,
        );
    }

    /**
     * @param  string $code
     * @param  array  $gov
     * @return array
     */
    private static function findProvinceByCode($code, array $gov)
    {
        if ($code === '') {
            return array();
        }

        foreach ($gov['provinces'] as $province) {
            if ((string) $province['code'] === $code) {
                return array($province);
            }
        }

        return array();
    }

    /**
     * @param  string $cityCode
     * @param  string $provinceCode
     * @param  array  $gov
     * @return array
     */
    private static function findCityByCode($cityCode, $provinceCode, array $gov)
    {
        if ($cityCode === '') {
            return array();
        }

        foreach ($gov['cities'] as $city) {
            if ((string) $city['code'] === $cityCode
                && (string) $city['provinceCode'] === $provinceCode
            ) {
                return array($city);
            }
        }

        return array();
    }

    // ============================================================
    // 树查找解析（type = 1）
    // ============================================================

    /**
     * 按 省 → 市 → 区 的顺序在扁平表里找"片段以某名称开头"的记录，
     * 找到后用 code 归属把上下级补齐。
     *
     * @param  string $fragment
     * @param  array  $hasParseResult
     * @param  array  $gov
     * @return array
     */
    private static function parseRegionTree($fragment, array $hasParseResult, array $gov)
    {
        $province = array_values(isset($hasParseResult['province']) ? $hasParseResult['province'] : array());
        $city = array_values(isset($hasParseResult['city']) ? $hasParseResult['city'] : array());
        $area = array_values(isset($hasParseResult['area']) ? $hasParseResult['area'] : array());
        $detail = array();

        // 从省开始查找
        if (count($province) === 0 || empty($province[0])) {
            foreach ($gov['provinces'] as $tempProvince) {
                $matched = self::matchPrefix($fragment, (string) $tempProvince['name']);
                if ($matched !== '') {
                    $province = array($tempProvince);
                    $fragment = self::replaceFirst($matched, '', $fragment);
                    break;
                }
            }
        }

        // 从市开始查找
        if (count($city) === 0 || empty($city[0])) {
            $fragmentBeforeCity = $fragment;
            $fragment = self::stripLeadingAdminNoise($fragment);

            $currentProvince = (count($province) > 0 && !empty($province[0])) ? $province[0] : null;

            foreach ($gov['cities'] as $tempCity) {
                $provinceCode = (string) $tempCity['provinceCode'];

                if ($currentProvince !== null) {
                    if ((string) $currentProvince['code'] !== $provinceCode) {
                        continue;
                    }
                    $matched = self::matchPrefix($fragment, (string) $tempCity['name']);
                    if ($matched !== '') {
                        $city = array($tempCity);
                        $fragment = self::replaceFirst($matched, '', $fragment);
                        break;
                    }
                } else {
                    $matched = self::matchPrefix($fragment, (string) $tempCity['name']);
                    if ($matched !== '') {
                        $city = array($tempCity);
                        // 反推省
                        foreach ($gov['provinces'] as $candidateProvince) {
                            if ((string) $candidateProvince['code'] === $provinceCode) {
                                $province = array($candidateProvince);
                                break;
                            }
                        }
                        $fragment = self::replaceFirst($matched, '', $fragment);
                        break;
                    }
                }
            }

            // 剥离后仍没匹配到市，恢复原片段
            if (count($city) === 0 || empty($city[0])) {
                $fragment = $fragmentBeforeCity;
            }
        }

        // 从区开始查找
        //
        // 分两轮：先按"市"精确匹配（徐州鼓楼区 vs 南京鼓楼区 只能靠市区分），
        // 第一轮没结果再退回按"省"匹配（省直辖县级市如仙桃、神农架，
        // 在区划表里挂在省下、市一级的归属与普通地级市不同）。
        $fragmentBeforeArea = $fragment;
        $fragment = self::stripLeadingAdminNoise($fragment);

        $currentProvince = (count($province) > 0 && !empty($province[0])) ? $province[0] : null;
        $currentCity = (count($city) > 0 && !empty($city[0])) ? $city[0] : null;

        $scopes = array();
        if ($currentCity !== null) {
            $scopes[] = 'city';
        }
        if ($currentProvince !== null) {
            $scopes[] = 'province';
        }
        if (count($scopes) === 0) {
            $scopes[] = 'any';
        }

        foreach ($scopes as $scope) {
            // 同一 scope 内取"匹配前缀最长"的区县，避免 前/中/后旗 这类共享前缀串名
            $bestArea = null;
            $bestMatched = '';
            $bestLen = 0;

            foreach ($gov['areas'] as $tempArea) {
                $provinceCode = (string) $tempArea['provinceCode'];
                $cityCode = (string) $tempArea['cityCode'];

                if ($scope === 'city' && $cityCode !== (string) $currentCity['code']) {
                    continue;
                }
                if ($scope === 'province' && $provinceCode !== (string) $currentProvince['code']) {
                    continue;
                }
                // 省回退：已识别到市时，只接受属于该市的区县（防止「城区/桥西区」串到别的市）
                if ($scope === 'province' && $currentCity !== null
                    && $cityCode !== (string) $currentCity['code']
                ) {
                    continue;
                }

                $matched = self::matchPrefix($fragment, (string) $tempArea['name']);
                if ($matched === '') {
                    continue;
                }

                $matchedLen = self::charLen($matched);
                if ($matchedLen > $bestLen) {
                    $bestLen = $matchedLen;
                    $bestArea = $tempArea;
                    $bestMatched = $matched;
                }
            }

            if ($bestArea !== null) {
                $tempArea = $bestArea;
                $matched = $bestMatched;
                $provinceCode = (string) $tempArea['provinceCode'];
                $cityCode = (string) $tempArea['cityCode'];
                $area = array($tempArea);

                if ($currentCity === null) {
                    foreach ($gov['cities'] as $candidateCity) {
                        if ((string) $candidateCity['code'] === $cityCode) {
                            $city = array($candidateCity);
                            break;
                        }
                    }
                }

                if ($currentProvince === null) {
                    foreach ($gov['provinces'] as $candidateProvince) {
                        if ((string) $candidateProvince['code'] === $provinceCode) {
                            $province = array($candidateProvince);
                            break;
                        }
                    }
                }

                $fragment = self::stripLegacyDistrictSuffix(
                    self::replaceFirst($matched, '', $fragment),
                    $matched,
                    (string) $tempArea['name']
                );

                break;
            }
        }

        // 剥离后仍没匹配到区，恢复原片段
        if (count($area) === 0) {
            $fragment = $fragmentBeforeArea;
        }

        // 容错：县→区（如蓟县→蓟州区、延庆县→延庆区）
        // 仅在已知省/市时启用：否则片段可能是未识别正文，全局匹配会串到外省同名区（资阳→资阳区）
        if (count($area) === 0 && ($currentProvince !== null || $currentCity !== null)
            && (self::contains($fragment, '县') || self::contains($fragment, '市'))
        ) {
            $cityCode = $currentCity !== null ? (string) $currentCity['code'] : '';
            $provinceCode = $currentProvince !== null ? (string) $currentProvince['code'] : '';

            foreach (array(array('县', '区'), array('市', '区')) as $suffixPair) {
                $oldSuffix = $suffixPair[0];
                $newSuffix = $suffixPair[1];

                $index = self::charIndex($fragment, $oldSuffix, 1);
                if ($index === -1) {
                    continue;
                }

                $prefix = self::subStr($fragment, 0, $index);
                $prefixLength = self::charLen($prefix);

                for ($i = $prefixLength; $i > 0; $i--) {
                    // 只在「整段前缀」或前缀本身仅一个字时接受：区名干必须紧贴 县/市 后缀。
                    // 否则正文首字会被误当成区名干（「中国…」→中山区、「儋州…」→海棠区、「安岳」→岳麓区）。
                    if ($i === 1 && $prefixLength > 1) {
                        continue;
                    }
                    $candidate = self::subStr($prefix, $prefixLength - $i, $i);
                    foreach ($gov['areas'] as $tempArea) {
                        $name = (string) $tempArea['name'];
                        if (!self::startsWith($name, $candidate) || !self::endsWith($name, $newSuffix)) {
                            continue;
                        }
                        if (($provinceCode === '' || (string) $tempArea['provinceCode'] === $provinceCode)
                            && ($cityCode === '' || (string) $tempArea['cityCode'] === $cityCode)
                        ) {
                            $area = array($tempArea);
                            $fragment = $name . self::subStr($fragment, $index + 1);
                            break;
                        }
                    }
                    if (count($area) > 0) {
                        break;
                    }
                }

                if (count($area) > 0) {
                    break;
                }
            }
        }

        if (trim($fragment) !== '') {
            $detail[] = trim($fragment);
        }

        return array(
            'province' => $province,
            'city' => $city,
            'area' => $area,
            'detail' => $detail,
        );
    }

    /**
     * 用名称的最长前缀去匹配片段开头
     *
     * @param  string $fragment
     * @param  string $name
     * @return string 命中的前缀，未命中返回空串
     */
    private static function matchPrefix($fragment, $name)
    {
        $length = self::charLen($name);
        for ($i = $length; $i > 1; $i--) {
            $candidate = self::subStr($name, 0, $i);
            if (self::startsWith($fragment, $candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    // ============================================================
    // 通用工具
    // ============================================================

    /**
     * 用户写的是旧区划名（延庆县）而官方名已改为 xx区 时，去掉残留的 县/市
     *
     * 仅在"官方名 == 已识别前缀 + 区"时生效；再用 STREET_FOLLOW_CHARS 排除
     * "东区东市街"这类把街道名首字误当成残留后缀的情况。
     */
    private static function stripLegacyDistrictSuffix($fragment, $matchedPrefix, $areaName)
    {
        if ($matchedPrefix !== ''
            && $areaName === $matchedPrefix . '区'
            && in_array(self::firstChar($fragment), array('县', '市'), true)
            && !self::contains(self::STREET_FOLLOW_CHARS, self::subStr($fragment, 1, 1))
        ) {
            return self::subStr($fragment, 1);
        }

        return $fragment;
    }

    /**
     * 去掉片段开头残留的行政后缀/噪声（不影响正文）
     *
     * 用户常用缩写/旧写法："文山州富宁县"（州）、"毕节地区织金县"（地区）、
     * "广西省南宁市"（省）、"重庆市县酉阳…"（县）。匹配完上级后残留的
     * 州/地区/省/县 会挡住下一级的前缀匹配，这里把它剥掉。
     *
     * 只剥离不会作为行政区名首字的字符（州/盟/省/县/旗/镇/乡 及多字噪声），
     * 故意不含「市 / 区」——避免误伤「市中区」「城区」这类真实区名。
     */
    private static function stripLeadingAdminNoise($fragment)
    {
        static $multi = array(
            '自治区直辖县级行政区划', '省直辖县级行政区划', '直辖县级行政区划',
            '自治区直辖县', '省直辖县', '地区',
        );
        foreach ($multi as $token) {
            if (self::startsWith($fragment, $token)) {
                return self::subStr($fragment, self::charLen($token));
            }
        }

        // 只剥离"绝不可能作为行政区名首字"的字符：州/盟/省/县/旗
        // （镇/乡/市/区 会出现在真实地名开头，如 镇江、乡城、市中区，不能剥）
        $first = self::firstChar($fragment);
        if (in_array($first, array('州', '盟', '省', '县', '旗'), true)) {
            // 后面紧跟街道类字符时视为正文（如「州前街」），不剥离
            if (!self::contains(self::STREET_FOLLOW_CHARS, self::subStr($fragment, 1, 1))) {
                return self::subStr($fragment, 1);
            }
        }

        return $fragment;
    }

    /**
     * 按空格切分、去空、去掉纯数字片段，再把含 省/市/区/县/镇 的片段前移
     *
     * @param  string $address
     * @return array
     */
    private static function splitAddress($address)
    {
        $fragments = array();
        foreach (explode(' ', $address) as $fragment) {
            $fragment = trim($fragment);
            if ($fragment === '' || preg_match('/^\d+$/', $fragment)) {
                continue;
            }
            $fragments[] = $fragment;
        }

        $result = array();
        foreach (self::ORDER_KEYWORDS as $keyword) {
            $index = 0;
            while ($index < count($fragments)) {
                if (self::contains($fragments[$index], $keyword)) {
                    $spliced = array_splice($fragments, $index, 1);
                    $result[] = $spliced[0];
                } else {
                    $index++;
                }
            }
        }

        return array_merge($result, $fragments);
    }

    /**
     * 保序去重（先 trim，丢掉空片段）
     *
     * @param  array $values
     * @return array
     */
    private static function uniqueList(array $values)
    {
        $result = array();
        $seen = array();

        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $result[] = $value;
        }

        return $result;
    }

    /**
     * 按字符长度升序稳定排序（等价于 Python 的 sorted(list, key=len)）
     *
     * @param  array $values
     * @return array
     */
    private static function sortByLength(array $values)
    {
        $decorated = array();
        foreach (array_values($values) as $index => $value) {
            $decorated[] = array(
                'value' => $value,
                'len' => self::charLen($value),
                'index' => $index,
            );
        }

        usort($decorated, function ($a, $b) {
            if ($a['len'] === $b['len']) {
                if ($a['index'] === $b['index']) {
                    return 0;
                }

                return $a['index'] < $b['index'] ? -1 : 1;
            }

            return $a['len'] < $b['len'] ? -1 : 1;
        });

        $result = array();
        foreach ($decorated as $item) {
            $result[] = $item['value'];
        }

        return $result;
    }

    /**
     * 参数归一化：非法值回落到默认值，不抛异常
     *
     * @param  mixed $raw
     * @param  int   $default
     * @return int
     */
    private static function normalizeInt($raw, $default)
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            return (int) $raw;
        }
        if (is_string($raw) && preg_match('/^\s*[+-]?\d+\s*$/', $raw)) {
            return (int) trim($raw);
        }

        return $default;
    }

    /**
     * 宽松布尔归一化："0"/"false"/"off"/"no"/"" → false，其余非空 → true
     *
     * @param  mixed $raw
     * @param  bool  $default
     * @return bool
     */
    private static function normalizeBool($raw, $default = false)
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return $raw != 0;
        }
        if (is_string($raw)) {
            $value = strtolower(trim($raw));
            if ($value === '') {
                return $default;
            }
            return !in_array($value, array('0', 'false', 'off', 'no', 'null', 'none'), true);
        }

        return $default;
    }

    // -------- UTF-8 字符级工具（有 mbstring 用之，没有则 preg 兜底） --------

    /**
     * @param  string $value
     * @return int
     */
    private static function charLen($value)
    {
        if ($value === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        // UTF-8 中非续字节（0b10xxxxxx）的个数即为字符数
        $count = 0;
        $bytes = strlen($value);
        for ($i = 0; $i < $bytes; $i++) {
            if ((ord($value[$i]) & 0xC0) !== 0x80) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 等价于 Python 的 value[start:start+length]
     *
     * @param  string   $value
     * @param  int      $start
     * @param  int|null $length
     * @return string
     */
    private static function subStr($value, $start, $length = null)
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length, 'UTF-8');
        }

        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return '';
        }

        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);

        return implode('', $slice);
    }

    /**
     * @param  string $value
     * @return string
     */
    private static function firstChar($value)
    {
        return self::subStr($value, 0, 1);
    }

    /**
     * @param  string $value
     * @return string
     */
    private static function lastChar($value)
    {
        return self::subStr($value, -1, 1);
    }

    /**
     * 等价于 Python 的 needle in haystack（空串视为命中）
     *
     * @param  string $haystack
     * @param  string $needle
     * @return bool
     */
    private static function contains($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }

        return strpos($haystack, $needle) !== false;
    }

    /**
     * @param  string $haystack
     * @param  string $prefix
     * @return bool
     */
    private static function startsWith($haystack, $prefix)
    {
        if ($prefix === '') {
            return true;
        }

        return strncmp($haystack, $prefix, strlen($prefix)) === 0;
    }

    /**
     * @param  string $value
     * @param  string $suffix
     * @return bool
     */
    private static function endsWith($value, $suffix)
    {
        if ($suffix === '') {
            return true;
        }

        return self::subStr($value, -self::charLen($suffix)) === $suffix;
    }

    /**
     * 等价于 Python 的 value.replace(search, replace, 1)（只替换第一处）
     *
     * @param  string $search
     * @param  string $replace
     * @param  string $value
     * @return string
     */
    private static function replaceFirst($search, $replace, $value)
    {
        if ($search === '') {
            return $value;
        }

        $pos = strpos($value, $search);
        if ($pos === false) {
            return $value;
        }

        return substr_replace($value, $replace, $pos, strlen($search));
    }

    /**
     * 等价于 Python 的 value.find(needle, start)，返回字符下标；找不到返回 -1
     *
     * @param  string $value
     * @param  string $needle
     * @param  int    $start
     * @return int
     */
    private static function charIndex($value, $needle, $start = 0)
    {
        if ($needle === '') {
            return $start;
        }

        if ($start <= 0) {
            $pos = strpos($value, $needle);

            return $pos === false ? -1 : self::charLen(substr($value, 0, $pos));
        }

        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return -1;
        }
        $needleChars = preg_split('//u', $needle, -1, PREG_SPLIT_NO_EMPTY);
        if ($needleChars === false) {
            return -1;
        }

        $total = count($chars);
        $needleLen = count($needleChars);

        for ($i = $start; $i + $needleLen <= $total; $i++) {
            $matched = true;
            for ($j = 0; $j < $needleLen; $j++) {
                if ($chars[$i + $j] !== $needleChars[$j]) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $i;
            }
        }

        return -1;
    }
}
