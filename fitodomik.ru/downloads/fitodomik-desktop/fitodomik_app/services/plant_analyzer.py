from __future__ import annotations
import logging
import time
from datetime import datetime
from pathlib import Path
from typing import Any
from fitodomik_app .core .models import AnalysisResult
from fitodomik_app .core .paths import PHOTOS_DIR
from fitodomik_app .core .performance import log_perf
from fitodomik_app .core .settings import SettingsManager
from .api_client import FitoDomikApiClient
_CV_MODULES =None
CAUSE_FUNGAL_INFECTION ="Грибковая инфекция"
SOLUTION_FUNGICIDE ="Обработать фунгицидами"
SOLUTION_INSECTICIDE ="Обработать инсектицидами"
DISEASES_DB ={
"yellow_leaves":{
"name":"Хлороз",
"description":"Пожелтение листьев",
"causes":["Недостаток железа","Переувлажнение","Недостаток азота"],
"solutions":["Добавить железосодержащие удобрения","Уменьшить полив","Внести азотные удобрения"],
},
"brown_spots":{
"name":"Грибковое заболевание",
"description":"Коричневые пятна на листьях",
"causes":[CAUSE_FUNGAL_INFECTION,"Избыточная влажность","Плохая вентиляция"],
"solutions":[SOLUTION_FUNGICIDE,"Улучшить вентиляцию","Удалить пораженные листья"],
},
"white_powder":{
"name":"Мучнистая роса",
"description":"Белый налет на листьях",
"causes":[CAUSE_FUNGAL_INFECTION,"Высокая влажность","Резкие перепады температуры"],
"solutions":[SOLUTION_FUNGICIDE,"Снизить влажность воздуха","Обеспечить стабильную температуру"],
},
"leaf_curl":{
"name":"Курчавость листьев",
"description":"Деформация и скручивание листьев",
"causes":["Вирусная инфекция","Недостаток кальция","Повреждение насекомыми"],
"solutions":["Удалить пораженные части","Внести кальциевые удобрения",SOLUTION_INSECTICIDE],
},
"black_spots":{
"name":"Черная пятнистость",
"description":"Черные пятна на листьях",
"causes":[CAUSE_FUNGAL_INFECTION,"Высокая влажность","Недостаточная циркуляция воздуха"],
"solutions":[SOLUTION_FUNGICIDE,"Улучшить проветривание","Удалить пораженные листья"],
},
"wilting":{
"name":"Увядание",
"description":"Общее увядание растения",
"causes":["Недостаток воды","Корневая гниль","Бактериальная инфекция"],
"solutions":["Проверить режим полива","Проверить корневую систему",SOLUTION_FUNGICIDE],
},
"mosaic":{
"name":"Мозаичная болезнь",
"description":"Мозаичный узор на листьях",
"causes":["Вирусная инфекция","Переносчики вирусов"],
"solutions":["Удалить пораженные растения","Бороться с насекомыми-переносчиками"],
},
}
PESTS_DB ={
"aphids":{
"name":"Тля",
"description":"Мелкие насекомые на листьях и стеблях",
"damage":"Высасывают сок из растения, вызывают деформацию листьев",
"solutions":[SOLUTION_INSECTICIDE,"Использовать мыльный раствор","Привлечь естественных хищников"],
},
"whitefly":{
"name":"Белокрылка",
"description":"Мелкие белые летающие насекомые",
"damage":"Высасывают сок, выделяют медвяную росу, переносят вирусы",
"solutions":["Использовать желтые липкие ловушки",SOLUTION_INSECTICIDE,"Регулярно осматривать нижнюю сторону листьев"],
},
"thrips":{
"name":"Трипсы",
"description":"Мелкие удлиненные насекомые",
"damage":"Повреждают листья и цветы, переносят вирусы",
"solutions":[SOLUTION_INSECTICIDE,"Использовать синие липкие ловушки","Удалять сорняки"],
},
"mealybugs":{
"name":"Мучнистые червецы",
"description":"Белые пушистые насекомые",
"damage":"Высасывают сок, выделяют медвяную росу",
"solutions":["Обработать спиртовым раствором","Использовать системные инсектициды","Изолировать пораженные растения"],
},
"scale_insects":{
"name":"Щитовки",
"description":"Неподвижные насекомые под защитным щитком",
"damage":"Высасывают сок, ослабляют растение",
"solutions":["Механически удалить","Обработать масляными препаратами","Использовать системные инсектициды"],
},
"caterpillars":{
"name":"Гусеницы",
"description":"Личинки бабочек различных размеров",
"damage":"Объедают листья, стебли и плоды",
"solutions":["Собирать вручную","Использовать биологические препараты",SOLUTION_INSECTICIDE],
},
}
class PlantAnalyzer :
    def __init__ (self ,settings :SettingsManager ,logger :logging .Logger |None =None )->None :
        self .settings =settings
        self .logger =(logger or logging .getLogger ("fitodomik")).getChild ("plant_analyzer")
        self .camera :Any |None =None
        self .color_percentages :dict [str ,float ]={}
        self .detected_diseases :list [dict [str ,Any ]]=[]
        self .detected_pests :list [dict [str ,Any ]]=[]
    def initialize_camera (self )->bool :
        started =time .perf_counter ()
        cv2 ,_ =self ._cv ()
        try :
            camera =cv2 .VideoCapture (int (self .settings .get ("camera_index",0 )))
            if not camera .isOpened ():
                raise RuntimeError ("Не удалось открыть камеру")
            self .camera =camera
            return True
        except Exception as exc :
            self .logger .info ("Ошибка инициализации камеры: %s",exc )
            self .release_camera ()
            return False
        finally :
            log_perf ("camera initialized",time .perf_counter ()-started )
    def capture_image (self ):
        if self .camera is None and not self .initialize_camera ():
            return None
        camera =self .camera
        if camera is None :
            self .logger .info ("Камера не открыта, снимок не получен")
            return None
        try :
            ret ,frame =camera .read ()
            if not ret :
                return None
            return frame .copy ()
        finally :
            if self .camera is not None and hasattr (self .camera ,"grab"):
                try :
                    self .camera .grab ()
                except Exception :
                    pass
    def release_camera (self )->None :
        if self .camera is not None :
            try :
                self .camera .release ()
            finally :
                self .camera =None
    def run_analysis (self ,api_client :FitoDomikApiClient |None =None )->AnalysisResult :
        started =time .perf_counter ()
        if not self .initialize_camera ():
            raise RuntimeError ("Ошибка инициализации камеры")
        try :
            image =self .capture_image ()
            if image is None :
                raise RuntimeError ("Не удалось получить изображение")
            detection_image ,mask ,_contours =self .detect_plant (image )
            analysis =self .analyze_health ()
            result =self .create_report (image ,detection_image ,mask ,analysis )
            if api_client and self .settings .get ("analysis_upload_enabled",True ):
                report_text =result .report_path .read_text (encoding ="utf-8")
                result .uploaded =api_client .upload_plant_images (
                result .original_path ,
                result .detection_path ,
                report_text ,
                )
            return result
        finally :
            self .release_camera ()
            log_perf ("plant analysis",time .perf_counter ()-started )
    def capture_photo_file (self ,prefix :str ="requested")->Path :
        started =time .perf_counter ()
        if not self .initialize_camera ():
            raise RuntimeError ("Ошибка инициализации камеры")
        try :
            image =self .capture_image ()
            if image is None :
                raise RuntimeError ("Не удалось получить изображение")
            cv2 ,_ =self ._cv ()
            PHOTOS_DIR .mkdir (parents =True ,exist_ok =True )
            output =PHOTOS_DIR /f"{prefix}_{datetime .now ().strftime ('%Y%m%d_%H%M%S')}.jpg"
            cv2 .imwrite (str (output ),image )
            return output
        finally :
            self .release_camera ()
            log_perf ("camera photo captured",time .perf_counter ()-started )
    def detect_plant (self ,image ):
        cv2 ,np =self ._cv ()
        leaf_colors =self ._leaf_colors ()
        self .color_percentages ={}
        hsv =cv2 .cvtColor (image ,cv2 .COLOR_BGR2HSV )
        detection_image =image .copy ()
        total_mask =np .zeros ((image .shape [0 ],image .shape [1 ]),dtype =np .uint8 )
        for color_range in leaf_colors .values ():
            mask =cv2 .inRange (hsv ,color_range ["lower"],color_range ["upper"])
            kernel =np .ones ((3 ,3 ),np .uint8 )
            mask =cv2 .morphologyEx (mask ,cv2 .MORPH_OPEN ,kernel )
            mask =cv2 .morphologyEx (mask ,cv2 .MORPH_CLOSE ,kernel )
            total_mask =cv2 .bitwise_or (total_mask ,mask )
        contours ,_ =cv2 .findContours (total_mask ,cv2 .RETR_EXTERNAL ,cv2 .CHAIN_APPROX_SIMPLE )
        filtered_contours =[contour for contour in contours if cv2 .contourArea (contour )>100 ]
        cv2 .drawContours (detection_image ,filtered_contours ,-1 ,(0 ,255 ,0 ),2 )
        plant_mask =np .zeros_like (total_mask )
        cv2 .drawContours (plant_mask ,filtered_contours ,-1 ,255 ,-1 )
        plant_pixels =np .count_nonzero (plant_mask )
        if plant_pixels >0 :
            for color_name ,color_range in leaf_colors .items ():
                mask =cv2 .inRange (hsv ,color_range ["lower"],color_range ["upper"])
                color_pixels =cv2 .countNonZero (cv2 .bitwise_and (mask ,plant_mask ))
                self .color_percentages [color_name ]=(color_pixels /plant_pixels )*100
        return detection_image ,plant_mask ,filtered_contours
    def detect_diseases (self )->None :
        self .detected_diseases =[]
        if self .color_percentages .get ("yellow",0 )>10 :
            self .detected_diseases .append (DISEASES_DB ["yellow_leaves"])
        if self .color_percentages .get ("brown",0 )>5 :
            self .detected_diseases .append (DISEASES_DB ["brown_spots"])
        if self .color_percentages .get ("light_green",0 )>15 :
            self .detected_diseases .append (DISEASES_DB ["white_powder"])
        if self .color_percentages .get ("yellow",0 )>20 and self .color_percentages .get ("brown",0 )>10 :
            self .detected_diseases .append (DISEASES_DB ["wilting"])
    def detect_pests (self )->None :
        self .detected_pests =[]
        if self .color_percentages .get ("yellow",0 )>15 and self .color_percentages .get ("brown",0 )>5 :
            self .detected_pests .append (PESTS_DB ["aphids"])
        if self .color_percentages .get ("brown",0 )>10 :
            self .detected_pests .append (PESTS_DB ["thrips"])
        if self .color_percentages .get ("light_green",0 )>20 and self .color_percentages .get ("yellow",0 )>10 :
            self .detected_pests .append (PESTS_DB ["whitefly"])
    def analyze_health (self )->dict [str ,str ]:
        self .detect_diseases ()
        self .detect_pests ()
        leaf_colors =self ._leaf_colors ()
        status ="нормальное"
        details :list [str ]=[]
        recommendations :list [str ]=[]
        if self .color_percentages .get ("yellow",0 )>10 :
            status ="требует внимания"
            details .append ("Обнаружено значительное пожелтение листьев")
            recommendations .append ("Проверьте режим полива")
            recommendations .append ("Проверьте уровень освещенности")
        if self .color_percentages .get ("brown",0 )>5 :
            status ="требует внимания"
            details .append ("Обнаружены коричневые участки на листьях")
            recommendations .append ("Проверьте на наличие заболеваний")
            recommendations .append ("Удалите поврежденные листья")
        for disease in self .detected_diseases :
            details .append (f"{disease ['name']}: {disease ['description']}")
            recommendations .extend (disease ["solutions"])
        for pest in self .detected_pests :
            details .append (f"{pest ['name']}: {pest ['description']}")
            recommendations .extend (pest ["solutions"])
        if not details :
            recommendations .append ("Поддерживайте текущий режим ухода")
        return {
        "timestamp":datetime .now ().strftime ("%Y-%m-%d %H:%M:%S"),
        "состояние":status ,
        "распределение цветов":"; ".join (
        f"{leaf_colors [key ]['name']}: {value :.1f}%"
        for key ,value in self .color_percentages .items ()
        if value >1
        ),
        "детали":"; ".join (details )if details else "отклонений не выявлено",
        "рекомендации":"; ".join (recommendations ),
        }
    def create_report (self ,original_image ,detection_image ,mask ,analysis :dict [str ,str ])->AnalysisResult :
        cv2 ,_ =self ._cv ()
        output_dir =Path (self .settings .get ("analysis_output_dir"))
        output_dir .mkdir (parents =True ,exist_ok =True )
        timestamp =datetime .now ().strftime ("%Y%m%d_%H%M%S")
        original_path =output_dir /f"original_{timestamp }.jpg"
        detection_path =output_dir /f"detection_{timestamp }.jpg"
        mask_path =output_dir /f"mask_{timestamp }.jpg"
        report_path =output_dir /f"analysis_{timestamp }.txt"
        for image ,path ,latest_name in (
        (original_image ,original_path ,"last_original.jpg"),
        (detection_image ,detection_path ,"last_detection.jpg"),
        (mask ,mask_path ,"last_mask.jpg"),
        ):
            encoded ,buffer =cv2 .imencode (".jpg",image )
            if not encoded :
                raise OSError ("Не удалось закодировать изображение анализа")
            data =buffer .tobytes ()
            path .write_bytes (data )
            (output_dir /latest_name ).write_bytes (data )
        report_text =self ._build_report_text (analysis )
        report_path .write_text (report_text ,encoding ="utf-8")
        (output_dir /"last_report.txt").write_text (report_text ,encoding ="utf-8")
        self ._cleanup_old_analysis_files (output_dir )
        return AnalysisResult (
        analysis =analysis ,
        original_path =original_path ,
        detection_path =detection_path ,
        mask_path =mask_path ,
        report_path =report_path ,
        )
    def _cleanup_old_analysis_files (self ,output_dir :Path )->None :
        try :
            keep =max (20 ,int (self .settings .get ("analysis_retention_files",240 )))
        except (TypeError ,ValueError ):
            keep =240
        for pattern in ("original_*.jpg","detection_*.jpg","mask_*.jpg","analysis_*.txt"):
            files =[]
            for path in output_dir .glob (pattern ):
                try :
                    files .append ((path .stat ().st_mtime ,path ))
                except Exception :
                    continue
            files .sort (reverse =True )
            for _mtime ,path in files [keep :]:
                try :
                    path .unlink ()
                except Exception :
                    pass
    def _build_report_text (self ,analysis :dict [str ,str ])->str :
        lines =[
        "АНАЛИЗ СОСТОЯНИЯ РАСТЕНИЯ",
        f"Дата анализа: {analysis ['timestamp']}",
        "",
        f"СОСТОЯНИЕ: {analysis ['состояние']}",
        "",
        "РАСПРЕДЕЛЕНИЕ ЦВЕТОВ:",
        ]
        self ._append_color_report (lines )
        lines .extend (["","ДЕТАЛИ АНАЛИЗА:"])
        self ._append_detection_report (lines )
        lines .extend (["","РЕКОМЕНДАЦИИ:"])
        recommendations =analysis .get ("рекомендации","")
        if recommendations :
            lines .extend (f"- {item }"for item in recommendations .split ("; "))
        else :
            lines .append ("- Нет рекомендаций")
        return "\n".join (lines )+"\n"
    def _append_color_report (self ,lines :list [str ])->None :
        if not self .color_percentages :
            lines .append ("- Нет данных по цветам")
            return
        leaf_colors =self ._leaf_colors ()
        for color_key ,color_info in leaf_colors .items ():
            percent =self .color_percentages .get (color_key ,0.0 )
            lines .append (f"- {color_info ['name']}: {percent :.2f}%")
    def _append_detection_report (self ,lines :list [str ])->None :
        if not self .detected_diseases and not self .detected_pests :
            lines .append ("отклонений не выявлено")
            return
        for disease in self .detected_diseases :
            self ._append_disease_report (lines ,disease )
        for pest in self .detected_pests :
            self ._append_pest_report (lines ,pest )
    def _append_disease_report (self ,lines :list [str ],disease :dict [str ,Any ])->None :
        lines .extend (["",f"Болезнь: {disease ['name']}",f"Описание: {disease ['description']}","Возможные причины:"])
        lines .extend (f"  - {cause }"for cause in disease ["causes"])
        lines .append ("Рекомендации по лечению:")
        lines .extend (f"  - {solution }"for solution in disease ["solutions"])
    def _append_pest_report (self ,lines :list [str ],pest :dict [str ,Any ])->None :
        lines .extend (["",f"Вредитель: {pest ['name']}",f"Описание: {pest ['description']}"])
        if "damage"in pest :
            lines .append (f"Тип повреждений: {pest ['damage']}")
        lines .append ("Рекомендации по борьбе:")
        lines .extend (f"  - {solution }"for solution in pest ["solutions"])
    def _leaf_colors (self ):
        _ ,np =self ._cv ()
        return {
        "healthy_green":{
        "lower":np .array ([35 ,30 ,30 ]),
        "upper":np .array ([85 ,255 ,255 ]),
        "name":"здоровый зеленый",
        },
        "yellow":{
        "lower":np .array ([20 ,30 ,30 ]),
        "upper":np .array ([35 ,255 ,255 ]),
        "name":"желтый",
        },
        "brown":{
        "lower":np .array ([10 ,30 ,10 ]),
        "upper":np .array ([20 ,255 ,255 ]),
        "name":"коричневый",
        },
        "light_green":{
        "lower":np .array ([35 ,30 ,30 ]),
        "upper":np .array ([85 ,100 ,255 ]),
        "name":"светло-зеленый",
        },
        }
    def _cv (self ):
        global _CV_MODULES
        if _CV_MODULES is not None :
            return _CV_MODULES
        started =time .perf_counter ()
        import cv2
        import numpy as np
        _CV_MODULES =(cv2 ,np )
        log_perf ("opencv imported",time .perf_counter ()-started )
        return _CV_MODULES
