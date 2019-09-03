<?php
/**
 * Created by PhpStorm.
 * User: Huan
 * Date: 2019/3/22
 * Time: 16:24
 */
namespace app\admin\controller;
use app\common\controller\Filesbase;
use think\Db;
use think\Request;
use think\Session;
use think\Auth;

class Project extends  Research {
    /*
     * Project 列表,只显示有查看权限的
     */
    public function _initialize(){//权限验证,没有权限的人不能使用下面的方法
        parent::_initialize(); // TODO: Change the autogenerated stub
        //首先,尝试获得当前课题编号(因为传递参数的时候可能只传递了会议编号或者要求编号)
        $request = Request::instance();
        $project_id = null;
        if($request->has('project_id')){
            $project_id = $request->param('project_id');
        } else if($request->has('research_id')||isset($this->research_id)){
            if($request->has('research_id'))
                $research_id = $request->param('research_id');
            else
                $research_id = $this->research_id;
            $project_id = Db::name('research')
                ->where('id',$research_id)
                ->find()['project_id'];
        }
        // 然后根据课题编号获取权限
        // Principal = 2
        // Teammate = 1
        // Neither = 0
        // Undefined = -1
        // 没有Great/Great/Great权限 <= 1
        $this->authority = $this->userInProject($project_id);

        // 裁定有没有Great/great/great权限
        $auth = Auth::instance();
        if(!$auth->check("Great/great/great",session('user')['id'])){
            trace("NO Great/great/great authority");
            $this->authority = min(1,$this->authority);
        }

        trace("Get project authority : " . (string)$this->authority);



        // 修改cols,添加推送情况
        $this->cols[0][count($this->cols[0])] = [
            'field'=>'ispush',
            'title'=>'推送情况',
//                'minwidth'=>150,
//                'width'=>200,
        ];
    }

    // 项目处理部分
    public function index() {
        $name = Session::get("user.username");
        $data = Db::name('project')
            ->where("
            principal='$name'
            OR principal LIKE '$name,%'
            OR principal LIKE '%,$name,%'
            OR principal LIKE '%,$name' 
            OR teammate='$name'
            OR teammate LIKE '$name,%'
            OR teammate LIKE '%,$name,%'
            OR teammate LIKE '%,$name'
            ")
            ->order('id',"DESC")
            ->paginate(10);
        return view("index",[
            'title'=>'项目课题管理',
            'subtitle'=>'项目列表',
            'data'=>$data,
        ]);
    }
    public function project_add(){
        $data = [
            'name'=>'空项目',
            'time'=>date("Y-m-d H:i:s"),
            'principal'=>Session::get("user.username"),
        ];
        $result = Db::name('project')->insertGetId($data);
        if(!$result)$this->error("迷之错误");

        $data = [
            'name'=>"空项目",
            'time'=>date("Y-m-d H:i:s"),
            'project_id'=>$result,
        ];
        $result2 = Db::name('research')->insertGetId($data);
        if(!$result2)$this->error("迷之错误2");

        $result3 = Db::name('project')->where('id',$result)->update(['constant_id'=>$result2]);
        if(!$result3)$this->error("迷之错误3");
        else $this->redirect($_SERVER["HTTP_REFERER"]);
        $this->fetch();
    }

    /**
     * 项目编辑
     * @param $project_id           int     项目编号
     * @param null $conference_id   int     会议编号
     * @param null $function        string  功能名称
     */
    public function project_edit($project_id,$conference_id=null,$function = null,$request_id = null){
        $request = Request::instance();
        $post = $request->post();
        $project = Db::name("project")->where("id",$project_id)->find();
        if($post){
            $this->setAuthority(2);
            // 更新项目信息
            $data = [
                'name'=>$post['project_name'],
                'time'=>date("Y-m-d H:i:s"),
                'principal'=>trim($post['project_principal']),
                'teammate'=>trim($post['project_teammate']),
            ];
            $result = Db::name('project')->where(['id'=>$project_id])->update($data);
            if(!$result)$this->error('admin/project/project_edit : 发生错误,问题可能是没有键入修改');

            // 更新项目常数课题的信息
            $constant_id = Db::name('project')
                ->where('id',$project_id)
                ->find()['constant_id'];
            $result = Db::name('research')->where('id',$constant_id)->update(['name'=>$post['project_name']]);
            // 如果本来就没有更新name，那么常数课题的更新是一定会失败的
            // 但是我不想对此进行检查，所以就直接不检查了


            // 更新成功
            $this->redirect($_SERVER["HTTP_REFERER"]);
        }else{
            $this->setAuthority(1);
            // 获取项目所拥有的课题
            $range = $project['research'];//由于where不能接受第三个参数是[],所以只能添加一个-1作为哨兵了
            if(empty($range))$range=[-1];
            $research = Db::name('research')
                ->where('id','in',$range)
                ->order('id','DESC')
                ->paginate(10);


            // 获取项目的常数课题ID
            $research_id = $project['constant_id'];

            // 获取参数课题文件
            $data = Db::name('files')
                ->where('type',"research:$research_id")
                ->field('id,filename,abstract,type,datetime,author')
                ->order("id DESC")
                ->select();

            // 获取文件推送信息
            foreach ($data as &$file){
                $tmp = Db::name("pjtpushfile")
                    ->where([
                        'project_id' => $project_id,
                        'file_id' => $file['id'],
                    ])
                    ->find();
                if(!empty($tmp))
                    $file['ispush'] = "[已推送] ";
                else
                    $file['ispush'] = "[未推送] ";
            }
            $data_json = json_encode($data);
            $cols_json = json_encode($this->cols);

            // 获取会议信息
            $conference = Db::name('conference')
                ->where(['research_id'=>$research_id])
                ->order('id','DESC')
                ->select();

            // 如果没有输入选择的会议，默认选择第一个会议
            if($conference_id == null && !empty($conference)){
                $conference_id=$conference[0]['id'];
            }

            // 在会议信息中，取消被推送的会议的显示，并在名称在进行修改
            for($i = 0;$i < count($conference);$i++){
                // 获取推送关系
                $result = Db::name("pjtpushconf")
                    ->where(['project_id'=>$project_id,'conference_id'=>$conference[$i]['id']])
                    ->find();
                // 如果该会议是被推送的会议，就执行“取消显示”和“名称修改”
                if(!empty($result)){
                    # 取消被推送的会议的显示
//                    $conference[$i]['undisplay']=true;
                    # 修改名称
                    $researchName = Db::name("research")
                        ->where("id",$result['research_id'])
                        ->find()['name'];
                    $conference[$i]['name'] = $conference[$i]['name'] . "->" . $researchName;
                }
            }

            // 获取当前选中的会议的信息
            if($conference_id != null){
                // 遍历conference，并且选择id和conference_id相同的那个conference
                $conference_now = null;
                foreach ($conference as $conf){
                    if($conf['id'] == $conference_id){
                        $conference_now = $conf;
                    }
                }
                // 获取当前会议所对应的request
                //注意,在join中使用__FILES__ = prefix . files, 这是为了避免使用前缀
                $request = Db::name('request')
                    ->alias('a')
                    ->join('__FILES__ b','a.file_id=b.id','left')
                    ->field(["a.*","b.filename"])
                    ->where("a.conference_id",$conference_id)
                    ->order("a.id","DESC")
                    ->select();

                // 获取当前会议的推送信息
                // 先判断当前会议是蓝本会议还是推送复制的会议
                $result = Db::name("pjtpushconf")
                    ->where(['project_id'=>$project_id,'conference_id'=>$conference_id])
                    ->find();

                // 获取蓝本会议的ID，如果当前会议是蓝本会议值就为$conference_id,反之则为$result['origin_id']
                $origin_id = (!empty($result))?$result['origin_id']:$conference_id;
                $PushResearch = [[
                    "research_name"=>"蓝本",
                    "conference_id"=>$origin_id,
                ]];

                // 获取当前会议所被推送到的课题
                $result = Db::name("pjtpushconf")
                    ->alias('a')
                    ->join("__RESEARCH__ b","a.research_id=b.id")
                    ->where(['a.project_id'=>$project_id,'a.origin_id'=>$origin_id])
                    ->field("a.conference_id,b.name")
                    ->select();

                foreach ($result as $res){
                    $PushResearch[count($PushResearch)] = [
                        "research_name"=>"->" . $res['name'],
                        "conference_id"=>$res['conference_id'],
                    ];
                }

            } else $conference_now = $request = $PushResearch = [];





            // 获取会议要求的历史文件
            $history = [];
            if(!empty($request_id)){
                $req = Db::name('request')->where('id',$request_id)->find();
                $history = explode(',',$req['history']);
                if(!empty($history))
                    $history = Db::name('files')
                        ->where('id','in',$history)
                        ->field('id,filename,abstract,type,datetime,author')
                        ->order('id DESC')
                        ->select();
            }
            $history_json = json_encode($history);

            return view('',[
                'title'=>'项目课题管理',
                'subtitle'=>'项目编辑',
                'project'=>$project,                // 当前项目
                'history_json'=>$history_json,
                'data_json'=>$data_json,
                'cols_json'=>$cols_json,
                'research'=>$research,              // 项目课题
                'conference'=>$conference,          // 课题会议
                'conference_id'=>$conference_id,    // 当前课题会议id
                'conference_now'=>$conference_now,  // 当前会议
                'request'=>$request,                // 当前会议的要求
                'authority'=>$this->authority,      // 权限
                'function'=>$function,
                'PushResearch'=>$PushResearch,
            ]);
        }
    }
    protected function _project_del($project_id){
        $result='';
        $researches = Db::name('research')->where("project_id",$project_id)->select();
        foreach($researches as $key => $res){
            $result1 = $this->_research_del($res['id']);
            if(!empty($result1))
                $result = $result . $result1 . "<br>";
        }
        $result2 = Db::name('project')
            ->where('id',$project_id)
            ->delete();
        if(!$result2){
            $result = $result . "_project_del error : $project_id <br>";
        }
        Db::name("pjtpushconf")
            ->where("project_id",$project_id)
            ->delete();
        Db::name("pjtpushfile")
            ->where("project_id",$project_id)
            ->delete();
        trace("Delete Project : " . (string)$project_id);
        return $result;
    }
    public function project_del($project_id){
        $this->setAuthority(2);
        $result = $this->_project_del($project_id);
        if(empty($result)){
            $this->redirect($_SERVER["HTTP_REFERER"]);
        } else {
            trace("[ABError] $result");
            $this->error($result);
        }
    }

    // 项目文件处理部分
    // 转发器，用来跳转到不同的函数
    public function batchFileCtrl(){
        $post = $this->request->post();
        $submitType = $post['submitType'];
//        echo "<pre>";
//        print_r($post);
//        echo "</pre>";
//        return;
        switch ($submitType){
            case "Download":// 跳转到下载
                $this->setAuthority(1);
                return $this->batch_file_download();
                break;
            case "Push":// 跳转到推送
                $this->setAuthority(2);
                return $this->pjt_file_push();
                break;
            case "Delete":// 跳转到删除
                $this->setAuthority(2);
                $result = $this->batch_file_delete();
                if(empty($result)){
                    $this->success("删除成功");
                } else {
                    trace("[ABError]" . $result);
                }
                break;
            default:// 发生错误，进行日志记录，并且跳转到出错界面
                trace("[ABError][Undefined submitType]$submitType");
        }
        $this->error("0xf0f00010");
    }

    /**
     * 批量推送项目文件
     */
    protected function pjt_file_push(){
        $request = Request::instance();
        $post = $request->post();
        $files = json_decode($post['data']);
        $project_id = $post['project_id'];
        if(empty($files))$this->error("请先选择文件");


        foreach ($files as $file){
            $tmp = [
                'project_id'=>$project_id,
                'file_id'=>$file->id,
            ];
            // 判断是否存在以上推送条目
            $result = Db::name('pjtpushfile')
                ->where($tmp)
                ->find();
            // 如果有就去掉，如果没有就添加新的
            if(empty($result)){
                Db::name('pjtpushfile')->insert($tmp);
            } else {
                Db::name('pjtpushfile')
                    ->where($tmp)
                    ->delete();
            }
        }
        $this->redirect($_SERVER["HTTP_REFERER"]);
    }

    // 项目会议推送
    public function pjt_conf_push($project_id,$conference_id){
        // 合法性检查
        $this->setAuthority(2);
        if(empty($conference_id))$this->error("请先选择会议");
        if(empty($project_id))$this->error("请先选择项目");


        // 先判断当前会议是蓝本会议还是推送复制的会议
        $result = Db::name("pjtpushconf")
            ->where(['project_id'=>$project_id,'conference_id'=>$conference_id])
            ->find();
        // 获取蓝本会议的ID，如果当前会议是蓝本会议值就为$conference_id,反之则为$result['origin_id']
        $origin_id = (!empty($result))?$result['origin_id']:$conference_id;

        // 获取之前说推送的会议，并且删除会议和删除会议推送
        $result = Db::name("pjtpushconf")
            ->where(['project_id'=>$project_id,'origin_id'=>$origin_id])
            ->select();
        foreach($result as $res){
            $this->_conference_del($res['conference_id']);
        }
        Db::name("pjtpushconf")
            ->where(['project_id'=>$project_id,'origin_id'=>$origin_id])
            ->delete();
        $post = $this->request->post();
        $research = isset($post['pushResearch'])?$post['pushResearch']:[];
        // 遍历所有的课题
        foreach ($research as $res) {
            // 每个课题复制一个会议
            trace($res);
            $tmp = $this->_conference_copy($origin_id);
            // 给会议添加推送
            Db::name('pjtpushconf')->insert([
                'project_id' => $project_id,
                'conference_id' => $tmp,
                'research_id' => $res,
                'origin_id' => $origin_id,
            ]);
        }
        $this->redirect($_SERVER["HTTP_REFERER"]);

    }


    // 课题处理部分
    public function research_add($project_id){
        $this->setAuthority(2);
        $data = [
            'name'=>'空课题',
            'time'=>date("Y-m-d H:i:s"),
            'principal'=>Session::get("user.username"),
            'project_id'=>$project_id,
        ];
        $result = Db::name('research')->insert($data);              //添加新课题
        if(!$result)$this->error("课题添加失败(research_add)");
        $data = Db::name('research')->getLastInsID('id'); //获取课题编号
        $data2 = Db::name('project')
            ->field('research')
            ->where(['id'=>$project_id])
            ->find();                                                       //获取项目原本记录

        if(empty($data2['research'])) $data2['research'] = (string)$data;   //更新记录
        else $data2['research'] = $data2['research'] . "," . (string)$data;
        $result=Db::name('project')
            ->where((['id'=>$project_id]))
            ->update($data2);                                               //把新纪录存储到数据库中
        if($result)
            $this->redirect($_SERVER["HTTP_REFERER"]);
        else
            $this->error("致命问题: 添加了新课题,但是没更新project,问题点admin/project/research");
    }
    public function research_modify($research_id){
        $this->setAuthority(2);
        $request = Request::instance();
        $post = $request->post();
        $data = [
            'name'=>$post['name'],
            'principal'=>trim($post['principal']),
        ];
        $result = Db::name('research')->where(['id'=>$research_id])->update($data);
        if($result)
            $this->redirect($_SERVER["HTTP_REFERER"]);
        else
            $this->error("修改失败,请输入要修改的项目");
    }
    protected function _research_del($research_id){
        $result='';
        // 获取所有课题所属的会议,迭代删除
        $conferences = Db::name('conference')->where("research_id",$research_id)->select();
        foreach($conferences as $key => $con){
            $result1 = $this->_conference_del($con['id']);
            if(!empty($result1))
                $result = $result . $result1 . "<br>";
        }
        // 获取所有课题所属的文件,迭代删除
        $pattern = "research:$research_id";
        $files = Db::name('files')->where('type',$pattern)->select();
        foreach($files as $key => $file){
            $result2 = $this->file_del($file['id']);
            if(!empty($result2))
                $result = $result . $result2 . "<br>";
        }



        // 删除课题在数据库project表中的记录
        /*
         * 函数笔记
         * array_search(aim,array)
         * 在array中搜寻aim,并返回index
         *
         * ret = array_splice(array,index,length)
         * 取出array的[index,index+length)的部分以ret返回
         * 在array中会删除掉被取出的部分
         */
        $research = Db::name("research")->where('id',$research_id)->find(); //获取课题记录
        $project_id = $research['project_id'];
        $project = Db::name("project")->where("id",$project_id)->find();    //获取项目
        $data = $project['research'];//获取项目拥有的课题列表
        $expdata = explode(',',$data);//解码
        $index =  array_search($research_id,$expdata);//在课题列表中寻找这个课题的位置,注意,常数课题本身是不存在于课题列表中的
        if($index !== false)array_splice($expdata,$index,1);//按照位置删除课题,删除的课题数量为1
        $data = implode(",",$expdata);//编码
        $project = Db::name("project")->where("id",$project_id)->update(['research' => $data]);//修正


        // 删除课题在数据库research中的记录
        $result4 = Db::name('research')
            ->where('id',$research_id)
            ->delete();
        if(!$result4){
            $result = $result . "_research_del error : $research_id <br>";
        }


        trace("Delete Research : " . (string)$research_id);
        return $result;
    }
    public function research_del($research_id){
        $this->setAuthority(2);
        $result = $this->_research_del($research_id);
        if(empty($result)){
            $this->redirect($_SERVER["HTTP_REFERER"]);
        } else {
            trace("[ABError] $result");
            $this->error($result);
        }
    }

}


