<?php
/**
 * Created by PhpStorm.
 * User: Huan
 * Date: 2019/3/16
 * Time: 14:37
 * 以表显示的资料集合
 * 资料管理
 * 任务管理
 * 加入管理(伪)
 * 资产登记
 */
namespace app\common\controller;
use app\admin\model\Admin;
use app\common\controller\Adminbase;
use think\Db;
use think\Request;
use think\Session;

/**
 * Class filesbase
 * @package app\admin\controller
 * 文件管理类,保存着文件的上传/下载/删除
 * 和common的files模板配套使用
 */
class Filesbase extends AdminBase{
    public function _initialize(){
        parent::_initialize(); // TODO: Change the autogenerated stub
        if(!defined('path'))define("path", ROOT_PATH. 'public' . DS . 'uploads');

    }
    protected function file_add($label=''){
        // 获取表单上传文件 例如上传了001.jpg
        $file = request()->file('image');//这个是用来处理上传文件的
        //上面那个之所以叫做image,是因为input框中的id = image,这一点真是没搞死我....
        // 移动到框架应用根目录/public/uploads/ 目录下
        if($file){
            $info = $file->move(path);
            /*
             * echo $info->getExtension().'<br>';  // 输出 jpg
             * echo $info->getSaveName().'<br>';   // 输出 20160820/42a79759f284b767dfcb2a0197904287.jpg
             * echo $info->getFilename().'<br>';   // 输出 42a79759f284b767dfcb2a0197904287.jpg
             */
            if(!$info)return '[error] Filesbase: $file-move()错误?';
            $date = [
                'filename'=>$this->request->param('file_name'),
                'type'=>$this->request->param('file_type'),//文件类型
                'path'=>$info->getSaveName(),
                'datetime'=>date("Y-m-d H:i:s"),
                'author'=>Session::get("user.username"),
                'abstract'=>$this->request->param('file_abstract'),
                'size'=> filesize(path . DS . $info->getSaveName()),
                'label'=> $label,
            ];
            Db::name('files')->insert($date);
            return '';
        }
        else {
            return "没有上传相应的文件";
        }
    }
    protected function file_del($file_id){
        $result = '';
        //在物理上删除文件
        $data = Db::name('files')->where("id",$file_id)->find();
        if(empty($data))return('fatal error : 无法找到文件');


        // 为了使得Linux和Windows兼容，加入对地址的正则替换
//        $file = path . DS . $data['path'];
        $file =
            preg_replace('/[\\/\\\\]/', DS, path . DS . $data['path']);
        if(!file_exists($file)){    //如果文件不存在
            $result = $result . " <br> " . "[error] Can't find File $file_id";
        }else if(!unlink($file)){   //如果物理删除失败
            $result = $result ."<br>" . "[error] Can't unlink File $file_id";
        }

        // 如果该文件被推送过，删除
        Db::name("pjtpushfile")
            ->where('file_id',$file_id)
            ->delete();

        // 然后删除数据库中的数据
        $result2 = Db::name('files')->where("id",$file_id)->delete();    //从数据库上删除
        trace("Delete File : " . (string)$file_id);
        if(!$result2)
            $result = $result . "<br>" . '[error] 文件已经删除,数据库删除失败';
        return $result;// 如果没有问题，就返回''
    }
    protected function file_download($id){
        $data = Db::name('files')->where("id",$id)->find();
        $filename = $data['path'];  // {$filename} = {$data}/{$filename_}
        $pattern = '/[^\\.]*$/';//差点没把我气死
//        var_dump($pattern);
        $isMatched = preg_match($pattern, $filename, $filename_);
        if($isMatched)
            $filename_ = $data['filename'] . '.' . $filename_[0];
        else// 虽然由于框架问题,好像本身就不能上传没有后缀的文件,但是这里还是进行判断吧
            $filename_ = $data['filename'];


//        为了兼容Linux和Windows的地址修饰符，加入正则替换
//        $filepath = path . DS . $filename;
        $filepath = preg_replace('/[\\/\\\\]/', DS, path . DS . $filename);
        if(!file_exists($filepath)){
            $this->error("文件不存在");
        }else {
            //打开文件
            $file = fopen($filepath, "r");
            $sz = filesize($filepath);
            //输入文件标签
            Header("Content-type: application/octet-stream");
            Header("Accept-Ranges: bytes");
            Header("Accept-Length: " . $sz);
            Header("Content-Disposition: attachment; filename=" . $filename_);
            if($sz > 0)//这是为了处理某些空文件的,因为fread不能接受sz为0
                echo fread($file, $sz);
            fclose($file);
        }
    }

    /**
     * 复制id为$file_id的文件
     * @param $file_id
     * @return string 返回的编号
     */
    protected function file_copy($file_id){
        $file = Db::name('files')->where('id',$file_id)->find();
        unset($file['id']);
        $begin = $file['path'];
        $str="";
        for($i = 0;$i < 32;$i++)
            $str = $str. (string)dechex(rand(0,15));
        $end = preg_replace('/\\\\\w+\./', "\\\\$str.", $begin);
        $file['path'] = $end;
        $begin = preg_replace('/[\\/\\\\]/', DS, path . DS . $begin);
        $end = preg_replace('/[\\/\\\\]/', DS, path . DS . $end);
        copy($begin,$end);
        Db::name('files')->insert($file);
        return Db::name('files')->getLastInsID();
    }
}
