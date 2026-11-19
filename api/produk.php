<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}

require_once dirname(__DIR__).'/koneksi.php';
/** @var mysqli $conn */
$conn->set_charset('utf8mb4');

const PT='produk'; const OT='pesanan'; const OFK='produk_id'; const PK='id';

function sj(bool $ok,int $st,string $msg,$data=null,array $err=[]):void{
    http_response_code($st);
    $p=['success'=>$ok,'message'=>$msg];
    if($data!==null)$p['data']=$data;
    if($err)$p['errors']=$err;
    echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
}
function body():array{
    $r=file_get_contents('php://input');
    if($r===false||trim($r)==='')return[];
    $d=json_decode($r,true);
    if(json_last_error()!==JSON_ERROR_NONE)sj(false,400,'Body bukan JSON valid',null,['json'=>json_last_error_msg()]);
    return is_array($d)?$d:[];
}
function gid():int{
    $r=$_GET['id']??'';
    if($r===''||!ctype_digit((string)$r))sj(false,400,'Parameter "id" wajib integer > 0');
    $i=(int)$r; if($i<=0)sj(false,400,'Parameter "id" wajib > 0'); return $i;
}
function tex(mysqli $c,string $t):bool{
    try{
        $s=$c->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=?"
        );
        $s->bind_param('s',$t);
        $s->execute();
        return (int)$s->get_result()->fetch_row()[0] > 0;
    }catch(Throwable $e){
        return false;
    }
}

function cols(mysqli $c,string $t):array{
    static $K=[]; if(isset($K[$t]))return $K[$t];
    if(!tex($c,$t))return $K[$t]=[];
    $C=[];$R=$c->query("SHOW COLUMNS FROM `$t`");
    while($r=$R->fetch_assoc())$C[$r['Field']]=['t'=>$r['Type'],'k'=>$r['Key']??'','e'=>strtolower($r['Extra']??'')];
    return $K[$t]=$C;
}
function wr(array $m):bool{return !($m['k']==='PRI'&&str_contains($m['e'],'auto_increment'));}
function isc(string $t):bool{return (bool)preg_match('/(tinyint|smallint|mediumint|int|bigint|year)/',$t);}
function fcc(string $t):bool{return (bool)preg_match('/(float|double|decimal|numeric)/',$t);}
function cv(array $m,$v):array{
    if(isc($m['t']))return[(int)$v,'i'];
    if(fcc($m['t']))return[(float)(is_string($v)?str_replace(',','.',$v):$v),'d'];
    return[(string)$v,'s'];
}
function row(mysqli $c,int $id):?array{
    $K=array_keys(cols($c,PT));if(!$K)return null;
    $s=$c->prepare("SELECT `".implode('`,`',$K)."` FROM `".PT."` WHERE `".PK."`=? LIMIT 1");
    $s->bind_param('i',$id);$s->execute();$r=$s->get_result();
    if($r->num_rows){$o=$r->fetch_assoc();$s->close();return $o;}
    $s->close();return null;
}
function list_(mysqli $c):void{
    $C=cols($c,PT);if(!$C)sj(false,500,'Tabel `'.PT.'` tidak ditemukan');
    $K=array_keys($C);
    $w='';$ty='';$ps=[];
    if(isset($_GET['kategori'])&&array_key_exists('kategori',$C)){
        $v=trim((string)$_GET['kategori']);
        if($v!==''){$w=' WHERE `kategori`=?';$ty='s';$ps=[$v];}
    }
    $cnt=$c->prepare("SELECT COUNT(*) FROM `".PT."`".$w);
    if($ty)$cnt->bind_param($ty,...$ps);
    $cnt->execute();$tot=(int)$cnt->get_result()->fetch_row()[0];$cnt->close();
    $page=max(1,(int)($_GET['page']??1));
    $lim=min(100,max(1,(int)($_GET['limit']??10)));
    $off=($page-1)*$lim;
    $q=$c->prepare("SELECT `".implode('`,`',$K)."` FROM `".PT."`".$w." ORDER BY `".PK."` DESC LIMIT ? OFFSET ?");
    $all=$ps;$ty2=$ty.'ii';$all[]=$lim;$all[]=$off;
    $q->bind_param($ty2,...$all);$q->execute();
    $q->bind_param($ty2,...$all);$q->execute();
$res=$q->get_result();
$d=[];
while($r=$res->fetch_assoc())$d[]=$r;
$q->close();

    sj(true,200,'OK',[
        'items'=>$d,'meta'=>['page'=>$page,'limit'=>$lim,'total'=>$tot,
        'total_pages'=>(int)ceil($tot/$lim)]]);
}
function get_(mysqli $c,int $id):void{
    $r=row($c,$id);if(!$r)sj(false,404,'Produk tidak ditemukan');sj(true,200,'OK',$r);
}
/* =========================================
   BLOK 2: WRITE OPERATIONS & ROUTER
   ========================================= */

// --- CREATE ---
function create_(mysqli $c):void{
    $C=cols($c,PT);$p=body();
    $names=[];$vals=[];$ty='';
    
    // Validasi input against schema dinamis
    foreach($p as $k=>$v){
        if(array_key_exists($k,$C)&&wr($C[$k])){
            $names[]=$k;
            [$x,$t]=cv($C[$k],$v);
            $vals[]=$x;
            $ty.=$t;
        }
    }
    
    if(!$names)sj(false,400,'Minimal 1 field valid (kolom writable yang ada di payload)');
    
    // Bangun SQL INSERT dinamis
    $sql="INSERT INTO `".PT."` (`".implode('`,`',$names)."`) VALUES (".rtrim(str_repeat('?,',count($names)),',').")";
    
    try {
        $st=$c->prepare($sql);
        $st->bind_param($ty,...$vals);
        $st->execute();
        
        $id=(int)$c->insert_id;
        $st->close();
        
        sj(true,201,'Produk dibuat',['id'=>$id,'data'=>row($c,$id)]);
    } catch (mysqli_sql_exception $e) {
        // Tangkap error FK atau constraint lain secara spesifik
        if ($e->getCode() == 1452) {
             sj(false,400,'Gagal membuat produk: Referensi kategori tidak ditemukan.', null, ['db_error'=>'FK Violation']);
        }
        throw $e; // Re-throw ke handler global di bawah
    }
}

// --- UPDATE ---
function update_(mysqli $c,int $id):void{
    if(!row($c,$id))sj(false,404,'Produk tidak ditemukan');
    
    $C=cols($c,PT);$p=body();
    $names=[];$vals=[];$ty='';
    
    foreach($p as $k=>$v){
        if(array_key_exists($k,$C)&&wr($C[$k])){
            $names[]=$k;
            [$x,$t]=cv($C[$k],$v);
            $vals[]=$x;
            $ty.=$t;
        }
    }
    
    if(!$names)sj(false,400,'Tidak ada field untuk di-update');
    
    // Bangun SQL UPDATE dinamis
    $sql="UPDATE `".PT."` SET `".implode('`=?,`',$names)."`=? WHERE `".PK."`=?";
    $ty.='i'; // Tambah integer untuk ID di akhir
    $vals[]=$id;
    
    try {
        $st=$c->prepare($sql);
        $st->bind_param($ty,...$vals);
        $st->execute();
        $st->close();
        
        sj(true,200,'Produk di-update',['data'=>row($c,$id)]);
    } catch (mysqli_sql_exception $e) {
         if ($e->getCode() == 1452) {
             sj(false,400,'Gagal update: Referensi kategori tidak ditemukan.', null, ['db_error'=>'FK Violation']);
        }
        throw $e;
    }
}

// --- DELETE ---
function delete_(mysqli $c,int $id):void{
    if(!row($c,$id))sj(false,404,'Produk tidak ditemukan');
    
    // Cek relasi sebelum hapus (Soft Guard)
    if(tex($c,OT)){
        $oc=cols($c,OT);
        if(array_key_exists(OFK,$oc)){
            $q=$c->prepare("SELECT COUNT(*) FROM `".OT."` WHERE `".OFK."`=?");
            $q->bind_param('i',$id);
            $q->execute();
            $n=(int)$q->get_result()->fetch_row()[0];
            $q->close();
            
            if($n>0){
                sj(false,409,'Produk masih dipakai '.$n.' transaksi di `'.OT.'`, hapus relasinya dulu');
            }
        }
    }
    
    $s=$c->prepare("DELETE FROM `".PT."` WHERE `".PK."`=?");
    $s->bind_param('i',$id);
    $s->execute();
    $s->close();
    
    sj(true,200,'Produk dihapus',['id'=>$id]);
}

/* ===== ROUTER + ERROR GUARD ===== */
try {
    /** 
     * ANOTASI INI MEMBERSIHKAN WARNING LINTER DI VS CODE 
     * Memberitahu Intelephense bahwa $conn adalah objek mysqli
     */
    /** @var mysqli $conn */ 
    
    $m=$_SERVER['REQUEST_METHOD']??'GET';

    if($m==='GET'){
        isset($_GET['id']) ? get_($conn,gid()) : list_($conn);

    }elseif($m==='POST'){
        create_($conn);

    }elseif($m==='PUT'||$m==='PATCH'){
        if(!isset($_GET['id']))sj(false,400,'PUT/PATCH butuh ?id=');
        update_($conn,gid());

    }elseif($m==='DELETE'){
        if(!isset($_GET['id']))sj(false,400,'DELETE butuh ?id=');
        delete_($conn,gid());

    }else{
        sj(false,405,'Method '.$m.' tidak didukung');
    }

}catch(mysqli_sql_exception $e){
    // Logging error internal (opsional tapi disarankan di prod)
    // error_log("[SQL Error] ".$e->getMessage());
    
    // Response JSON standar untuk DB error
    sj(false,500,'DB error: '.$e->getMessage());

}catch(Throwable $e){
    // Catch-all untuk fatal PHP errors (undefined function, type error, dll)
    sj(false,500,'Terjadi kesalahan sistem: '.$e->getMessage());
}
