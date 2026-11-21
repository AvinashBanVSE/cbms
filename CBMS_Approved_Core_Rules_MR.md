# CBMS — मान्य मूलभूत संकल्पना आणि व्यावसायिक नियम

**दस्तऐवज प्रकार:** मंजूर संकल्पना व नियमांचे आधारदस्तऐवज  
**आवृत्ती:** 1.0  
**स्थिती:** मंजूर संकल्पनांचा आधार (Approved Baseline)  
**दिनांक:** 21 नोव्हेंबर 2026  
**भाषा:** मराठी

---

## दस्तऐवजाचा उद्देश आणि अधिकार

हा दस्तऐवज CBMS साठी चर्चेद्वारे स्पष्टपणे मंजूर केलेल्या मूलभूत संकल्पना, व्याख्या, व्यावसायिक नियम, संबंध, निर्बंध आणि विस्तारक्षमता यांची एकत्रित व अधिकृत नोंद आहे. पुढील Architecture Review, Codebase Gap Analysis, Technical Design, Testing आणि Implementation Planning यांसाठी हा संदर्भ आधार म्हणून वापरायचा आहे.

हा दस्तऐवज **व्यावसायिक/आर्किटेक्चरल नियम निश्चित करतो; विद्यमान Source Code त्या नियमांशी पूर्णपणे जुळतो असे प्रमाणित करत नाही.** विद्यमान Codebase चे स्वतंत्र, पुराव्यावर आधारित परीक्षण पुढील टप्प्यात करायचे आहे. त्या परीक्षणात आढळणारी विसंगती या दस्तऐवजातील मंजूर नियमांनुसार नोंदवायची आहे; कोडमधील सध्याची अंमलबजावणी नियमांचा पर्याय मानायची नाही.

या दस्तऐवजात चर्चा न झालेल्या किंवा स्पष्टपणे मंजूर न केलेल्या नवीन व्यावसायिक नियमांची भर घातलेली नाही. उदाहरणे केवळ नियम समजावण्यासाठी आहेत; ती नियमांची संपूर्ण किंवा मर्यादित यादी समजू नयेत.

## 0. नियमांची व्याप्ती आणि शब्दप्रयोग

### 0.1 सामान्य, डोमेन-सामान्य आणि विशिष्ट नियम

प्रत्येक नियमाचा लागू होण्याचा व्याप स्पष्ट करण्यासाठी खालील वर्गीकरण वापरले आहे.

| वर्ग | अर्थ | वापर |
|---|---|---|
| **[सामान्य / GENERAL]** | CBMS मधील सर्व संबंधित घटक, मॉड्यूल किंवा प्रक्रियांना लागू होणारा नियम | डेटा अखंडता, अधिकार, Audit, विस्तारक्षमता यांसारखे सर्वव्यापी नियम |
| **[डोमेन-सामान्य / DOMAIN-GENERAL]** | एका व्यावसायिक क्षेत्रातील सर्व संबंधित व्यवहार किंवा दस्तऐवज प्रकारांना लागू होणारा नियम | Transaction Exchange नियम Accounting, Inventory आणि इतर लागू व्यवहारांवर समान रीतीने लागू होणे |
| **[विशिष्ट / SPECIFIC]** | एखाद्या निश्चित Entity, Relationship, Workflow किंवा कृतीपुरता मर्यादित नियम | Main Portal–Sub-Portal संबंध किंवा Distributor–Shop Mapping नियम |
| **[उदाहरण / EXAMPLE]** | नियम स्पष्ट करण्यासाठी दिलेले उदाहरण; स्वतः स्वतंत्र नियम नाही | Invoice, Credit Note, Stock Receipt इत्यादी उदाहरणे |

एकाच नियमाला एकापेक्षा अधिक वर्ग लागू असल्यास ते स्पष्टपणे नमूद केले आहे. विशिष्ट उदाहरणावरून सामान्य नियमाचा व्याप अनावश्यकपणे कमी किंवा वाढवू नये.

### 0.2 बंधनकारक शब्द

- **आवश्यक / MUST:** पालन करणे बंधनकारक आहे.
- **मनाई / MUST NOT:** कृती किंवा वर्तन निषिद्ध आहे.
- **करता येईल / MAY:** मंजूर नियमांच्या मर्यादेत पर्याय उपलब्ध आहे.
- **शिफारस / SHOULD:** उद्दिष्टासाठी अपेक्षित पद्धत; परंतु ती स्वतंत्रपणे बंधनकारक नियम म्हणून मांडलेली नसल्यास तिला MUST समजू नये.

### 0.3 नियम आणि उदाहरण यांतील फरक

उदाहरणात Invoice वापरला असला तरी नियम फक्त Invoice पुरता मर्यादित होत नाही, जोपर्यंत तो स्पष्टपणे विशिष्ट Invoice नियम म्हणून नमूद केलेला नाही. तसेच एखादे उदाहरण दिले म्हणून त्या उदाहरणाबाहेरील इतर व्यवहारांना परवानगी किंवा मनाई निर्माण होत नाही.

---

# 1. मूलभूत आर्किटेक्चरल तत्त्वे

### 1.1 अपरिवर्तनीय Core आणि विस्तारक्षम प्रणाली [सामान्य]

CBMS ची मूलभूत Business Structure आणि Security/Data Integrity ची बंधने स्थिर राहतील. त्याभोवती Modules, Permissions, Roles, Workflows, Service Definitions आणि योग्य Configuration वाढवता येतील.

या तत्त्वाचे तीन स्वतंत्र भाग आहेत:

1. **Immutable Core:** निश्चित Business Entity Types, त्यांचा Hierarchy क्रम, मान्य Parent–Child संबंध आणि प्रतिबंधित संबंध हे Runtime Configuration द्वारे बदलता येणार नाहीत.
2. **Extensible Modules:** नवीन व्यावसायिक मॉड्यूल्स स्वतंत्र जबाबदाऱ्या, स्वतःचे Domain Logic आणि आवश्यक Integration सह विकसित करता येतील.
3. **Configurable Policies:** ज्या बाबी स्पष्टपणे Configuration साठी योग्य आहेत—उदा. Permission Assignments, Role Definitions, Approval Policy, Module Visibility, Renewal Policy—त्या नियंत्रित पद्धतीने बदलता येतील. Configuration मुळे Immutable Core किंवा सुरक्षा नियम मोडता कामा नयेत.

### 1.2 मॉड्युलर जबाबदारी [सामान्य]

Accounting, Inventory, HR, Warehouse आणि इतर व्यावसायिक क्षेत्रे स्वतंत्र Modules म्हणून रचायची. एका Module ची व्यावसायिक जबाबदारी दुसऱ्या Module मध्ये मिसळायची नाही. आवश्यक परस्पर व्यवहार स्पष्ट API, Event किंवा अन्य नियंत्रित Integration मधून करायचे.

उदाहरणार्थ, मालाची प्रत्यक्ष हालचाल Inventory/Warehouse क्षेत्रात नोंदवली जाईल; त्या हालचालीचा आर्थिक परिणाम Accounting क्षेत्रात योग्य नोंदींमधून प्रतिबिंबित होईल. एका Module ने दुसऱ्याचा मूळ डेटा अनधिकृतपणे बदलू नये.

### 1.3 Configuration विरुद्ध नवीन कार्यक्षमता [सामान्य]

Configuration चा उपयोग आधीपासून विकसित आणि समर्थित कार्यक्षमतेचे नियम/पर्याय व्यवस्थापित करण्यासाठी आहे. नवीन प्रकारची कृती, नवीन व्यवहार प्रक्रिया, नवीन सुरक्षा अंमलबजावणी किंवा नवीन Module चे कार्य प्रत्यक्ष तयार करण्यासाठी आवश्यक Application Code विकसित करावा लागू शकतो.

नवीन Permission Definition जोडणे आणि त्या Permission ने संरक्षित होणारी नवीन Business Operation विकसित करणे या दोन वेगळ्या गोष्टी आहेत. पहिली गोष्ट Registry/Configuration मधून व्यवस्थापित होऊ शकते; दुसरीसाठी संबंधित कार्यक्षमता व Server-side Enforcement विकसित करणे आवश्यक आहे.

---

# 2. अधिकृत संज्ञा आणि व्याख्या

| संज्ञा | व्याख्या |
|---|---|
| **Main Portal** | CBMS मधील सर्वोच्च Business Portal स्तर. त्याच्या खाली Sub-Portal आणि थेट Organization असू शकतात. |
| **Sub-Portal** | Main Portal च्या अधीन असलेला Portal स्तर. त्याच्या खाली Organization असू शकतात. |
| **Organization** | Main Portal किंवा Sub-Portal यांपैकी एका स्तराच्या थेट अधीन असलेली व्यावसायिक संस्था. तिच्या खाली Distributor आणि Shop हे स्वतंत्र Business Entities असू शकतात. |
| **Distributor** | एखाद्या Organization च्या थेट अधीन असलेला Business Entity. तो Shop चा Structural Parent नसतो. |
| **Shop** | एखाद्या Organization च्या थेट अधीन असलेला Business Entity. तो Distributor चा Structural Child नसतो. |
| **Structural Parent** | निश्चित Business Hierarchy मध्ये एखाद्या Entity चा थेट पालक स्तर. |
| **Structural Child** | निश्चित Business Hierarchy मध्ये एखाद्या Entity च्या थेट अधीन असलेली Entity. |
| **Ownership** | Business Entity वरील मालकी/मालकांची नोंद. ती Structural Parent संबंधापेक्षा स्वतंत्र आहे. |
| **Service Provider Relationship** | एखाद्या Organization किंवा संबंधित ग्राहक घटकाला सेवा पुरवणाऱ्या Provider शी असलेला संबंध. तो Structural Parent आणि Ownership यांच्यापेक्षा स्वतंत्रपणे स्पष्ट व अंमलात आणायचा. |
| **Distributor–Shop Mapping / Link** | एका Distributor आणि एका Shop यांच्यातील स्वतंत्र व्यावसायिक संबंध. हा Parent–Child संबंध नाही. |
| **Mapping Pair** | एका विशिष्ट Distributor ID आणि एका विशिष्ट Shop ID यांची जोडी. |
| **Mapping Request** | Mapping तयार करण्यासाठी एका बाजूने दुसऱ्या बाजूला पाठवलेली विनंती. |
| **Initiator** | Mapping Request सुरू करणारा Distributor किंवा Shop. |
| **Recipient** | त्या Request वर निर्णय घेणारी दुसरी बाजू. |
| **Transaction Exchange** | एका व्यवसायाने तयार केलेल्या संबंधित व्यावसायिक दस्तऐवज/नोंदीची माहिती Mapping द्वारे दुसऱ्या व्यवसायापर्यंत पोहोचवण्याची प्रक्रिया. |
| **Exchange Inbox / Staging** | प्राप्त झालेला दस्तऐवज तपासणी आणि निर्णयासाठी उपलब्ध असलेली तात्पुरती/कार्यप्रवाह नोंद; ती स्वतः Receiver ची Accounting/Inventory Posting नाही. |
| **Receiver Acceptance** | प्राप्तकर्ता व्यवसायाच्या अधिकृत व्यक्तीने दस्तऐवज स्वीकारून त्याची संबंधित नोंद स्वतःच्या व्यवसायाच्या प्रणालीत निर्माण करण्यास दिलेली संमती. |
| **Operational Record / Posting** | संबंधित व्यवसायाच्या अधिकृत Accounting, Inventory किंवा इतर व्यवहार नोंदीत दस्तऐवजाचा परिणाम नोंदवणे. |
| **Deactivation** | Business Lifecycle मधील संबंधित Business Entity ची Deactivated स्थिती. |
| **Permission** | विशिष्ट वापरकर्ता/भूमिका/व्यवसाय संदर्भात विशिष्ट कृती करण्याचा स्वतंत्र अधिकार. Mapping स्वतः Permission नाही. |

---

# 3. निश्चित Business Hierarchy

## 3.1 निश्चित Business Entity Types [विशिष्ट — अपरिवर्तनीय]

CBMS ची मूलभूत Business Hierarchy खालील पाच Entity Types पुरती निश्चित आहे:

1. Main Portal
2. Sub-Portal
3. Organization
4. Distributor
5. Shop

या मूलभूत Hierarchy मध्ये नवीन Business Entity Type जोडणे, विद्यमान Type चे स्थान बदलणे किंवा Hierarchy क्रम बदलणे मान्य नाही. कोणत्याही Admin किंवा Runtime Configuration मधून हे नियम बदलता येणार नाहीत.

## 3.2 मान्य Parent–Child संबंध [विशिष्ट — अपरिवर्तनीय]

| Parent Entity | Child Entity | निर्णय |
|---|---|---|
| Main Portal | Sub-Portal | **Allowed** |
| Main Portal | Organization | **Allowed** |
| Sub-Portal | Organization | **Allowed** |
| Organization | Distributor | **Allowed** |
| Organization | Shop | **Allowed** |
| Distributor | Shop | **Prohibited** |

वरील तक्त्यात स्पष्टपणे Allowed नसलेले इतर सर्व Parent–Child प्रकार निषिद्ध आहेत. विशेषतः Sub-Portal हा Main Portal च्या वर असू शकत नाही; Organization हा Main Portal किंवा Sub-Portal च्या वर/Parent असू शकत नाही; Distributor आणि Shop यांच्यात Parent–Child संबंध असू शकत नाही.

Distributor आणि Shop यांच्यात Mapping असू शकते; Mapping मुळे त्यांचा Hierarchy संबंध निर्माण होत नाही.

## 3.3 Cardinality — प्रत्येक Child चा एकच Structural Parent [विशिष्ट]

- प्रत्येक Sub-Portal चा नेमका एक Structural Parent Main Portal असावा.
- प्रत्येक Organization चा नेमका एक Structural Parent असावा: Main Portal किंवा Sub-Portal यांपैकी एक.
- प्रत्येक Distributor चा नेमका एक Structural Parent Organization असावा.
- प्रत्येक Shop चा नेमका एक Structural Parent Organization असावा.
- एका Main Portal अंतर्गत अनेक Sub-Portals आणि अनेक Organizations असू शकतात.
- एका Sub-Portal अंतर्गत अनेक Organizations असू शकतात.
- एका Organization अंतर्गत अनेक Distributors आणि अनेक Shops असू शकतात.

या cardinality मुळे एका Entity ला एकाच वेळी दोन Structural Parents देणे निषिद्ध आहे.

## 3.4 Distributor आणि Shop यांचा Organization संबंध अपरिवर्तनीय [विशिष्ट]

Distributor किंवा Shop तयार होताना त्याचा Structural Parent Organization निश्चित होईल. एकदा निश्चित झाल्यानंतर तो बदलता येणार नाही.

- Distributor दुसऱ्या Organization कडे Transfer/Reassign करता येणार नाही.
- Shop दुसऱ्या Organization कडे Transfer/Reassign करता येणार नाही.
- Distributor–Shop Mapping तयार करणे, स्वीकारणे, नाकारणे किंवा समाप्त करणे यामुळे कोणत्याही बाजूचा Structural Parent बदलणार नाही.
- Mapping वापरून Organization बदलणे, Ownership बदलणे किंवा Service Provider बदलणे शक्य नाही.

## 3.5 Structural Parent, Ownership आणि Service Provider यांची विभागणी [सामान्य]

ही तीन स्वतंत्र संकल्पना आहेत. त्यांचे स्वतंत्र डेटा-संबंध, Validation आणि Authorization असावेत.

- Structural Parent सांगतो की Entity निश्चित Hierarchy मध्ये कोणाच्या थेट अधीन आहे.
- Ownership सांगते की त्या Entity ची मालकी कोणाकडे आहे.
- Service Provider Relationship सांगतो की संबंधित ग्राहक घटकाला सेवा कोण पुरवतो.

एका संकल्पनेचा डेटा/Field दुसऱ्या संकल्पनेचा पर्याय म्हणून वापरू नये. Service Provider Migration झाल्यास त्याचा अर्थ आपोआप Structural Parent किंवा Ownership बदलला असा होत नाही.

## 3.6 Organization ची व्यावसायिक क्षमता [विशिष्ट]

Organization ची कार्यक्षमता/व्यावसायिक क्षमता त्याच्या लागू Business Modules आणि Policies नुसार निश्चित करता येईल. उदाहरणार्थ, Distribution-केंद्रित, Retail-केंद्रित किंवा दोन्ही क्षमता असलेली Organization अशी कार्यात्मक रूपे असू शकतात. अशा क्षमता म्हणजे नवीन Business Entity Types नाहीत आणि त्या निश्चित Hierarchy बदलत नाहीत.

---

# 4. Distributor–Shop Mapping

## 4.1 Mapping चे स्वरूप [विशिष्ट]

Distributor आणि Shop यांच्यातील संबंध हा स्वतंत्र Many-to-Many Mapping आहे.

- एका Distributor शी कितीही Shops Map करता येतील; व्यावसायिक संख्यात्मक मर्यादा नाही.
- एका Shop शी कितीही Distributors Map करता येतील; व्यावसायिक संख्यात्मक मर्यादा नाही.
- Mapping हा Structural Parent–Child संबंध नाही.
- Mapping मुळे `parent_id`, Organization, Ownership किंवा Service Provider Relationship बदलत नाही.
- वेगवेगळ्या Organizations मधील Distributor आणि Shop यांच्यात Mapping करता येते.

## 4.2 Request Initiation आणि निर्णय [विशिष्ट]

- Distributor किंवा Shop यांपैकी कोणतीही बाजू Mapping Request सुरू करू शकते.
- Request सुरू करणारी बाजू Initiator असेल.
- दुसरी बाजू Recipient असेल.
- Recipient कडे Request **Accept** किंवा **Reject** करण्याचा अधिकार असावा.
- Recipient च्या Acceptance नंतरच Mapping Active होईल.
- फक्त Request पाठवली म्हणून Mapping Active मानता येणार नाही.
- Initiation, Acceptance, Rejection आणि Termination संबंधित कृतींसाठी योग्य Permissions आवश्यक आहेत.

## 4.3 Duplicate प्रतिबंध आणि Mapping Pair ची अखंडता [विशिष्ट]

एखाद्या Mapping Pair साठी:

- Mapping आधीपासून Active असल्यास नवीन Request किंवा Mapping तयार करता येणार नाही.
- Request Pending असल्यास त्याच Pair साठी दुसरी Request तयार करता येणार नाही.
- Initiator Distributor असो किंवा Shop, Duplicate प्रतिबंध समान राहील.
- एकाच वेळी दोन्ही बाजूंनी Request सुरू करण्याचा प्रयत्न केला तरी एकाच Pair साठी Duplicate Pending/Active नोंद निर्माण होता कामा नये.
- हा प्रतिबंध फक्त त्या विशिष्ट Distributor–Shop Pair पुरता आहे; इतर Shops किंवा Distributors सोबत Mapping करण्यावर तो लागू होत नाही.

## 4.4 Mapping Request आणि Mapping चे स्थिती-नियम [विशिष्ट]

| स्थिती | अर्थ | पुढील परिणाम |
|---|---|---|
| **Pending** | Request पाठवली आहे; Recipient च्या निर्णयाची प्रतीक्षा | त्याच Pair साठी दुसरी Request निषिद्ध |
| **Active** | Recipient ने Accept केले आहे | Mapping नुसार पात्र Transaction Exchange शक्य; स्वतंत्र Permissions व Receiver Acceptance आवश्यक |
| **Rejected** | Recipient ने Request नाकारली | अंतिम स्थिती; त्याच Pair साठी पुन्हा नवीन Request निषिद्ध |
| **Withdrawn** | Initiator ने Pending Request मागे घेतली | त्या Request चा प्रवाह बंद; नवीन Request करता येईल |
| **Expired** | ठरवलेल्या वैधता कालावधीत निर्णय न झाल्याने Request कालबाह्य | त्या Request चा प्रवाह बंद; नवीन Request करता येईल |
| **Terminated** | पूर्वीची Active Mapping समाप्त केली | इतिहास कायम; नवीन Request सुरू करता येईल |

### 4.4.1 Rejection अंतिम आहे

Rejected ही Final स्थिती आहे. Rejected Request पुन्हा Pending करता येणार नाही. त्याच Distributor–Shop Pair साठी दुसऱ्या दिशेने किंवा नवीन Request म्हणून Rejection टाळण्याचा प्रयत्नही निषिद्ध आहे. Rejection चा निर्णय आणि त्याचा इतिहास कायम जतन करायचा.

### 4.4.2 Withdrawn, Expired आणि Terminated यांतील फरक

Withdrawn म्हणजे Initiator ने निर्णय होण्यापूर्वी स्वतः Request मागे घेतली. Expired म्हणजे लागू वैधता कालावधी संपला. Terminated म्हणजे आधी Active असलेली Mapping नंतर समाप्त केली. या तीन स्थिती Rejected सारख्या कायमस्वरूपी Pair-level प्रतिबंध निर्माण करत नाहीत; मंजूर नियमांनुसार त्यानंतर नवीन Request करता येईल.

### 4.4.3 Request Expiry

Pending Request साठी Expiry Period असावा. त्याचा कालावधी Configurable असावा; परंतु या दस्तऐवजात कोणतीही विशिष्ट संख्या/कालमर्यादा निश्चित केलेली नाही. Expiry झाल्यानंतर जुनी Request Expired म्हणून इतिहासात राहील.

### 4.4.4 Termination

Active Mapping दोन्ही बाजूंपैकी कोणत्याही बाजूच्या योग्य अधिकृत व्यक्तीकडून Terminate करता येईल. Termination मुळे जुने Mapping किंवा Transaction History हटवायचे नाही. Terminated Mapping वरून नवीन Automatic Transaction Exchange सुरू करता येणार नाही.

## 4.5 Mapping Permissions [सामान्य + विशिष्ट]

Mapping आणि Permission स्वतंत्र आहेत.

- Mapping Active आहे म्हणून कोणत्याही बाजूला दुसऱ्या बाजूच्या सर्व डेटावर किंवा कृतींवर अधिकार मिळत नाही.
- Request Initiate, Accept, Reject आणि Terminate करण्यासाठी स्वतंत्र व योग्य Permission आवश्यक आहे.
- Distributor च्या वतीने निर्णय घेणारा User त्या Distributor च्या वैध अधिकारक्षेत्रात असावा.
- Shop च्या वतीने निर्णय घेणारा User त्या Shop च्या वैध अधिकारक्षेत्रात असावा.
- Delegation असल्यास ती विशिष्ट Business, कृती, कालावधी आणि मंजूर Scope पुरती मर्यादित असावी.
- Mapping कोणतेही Role, Permission किंवा Delegated Authority आपोआप निर्माण करत नाही.

## 4.6 Mapping History आणि Audit [सामान्य]

Mapping संदर्भातील महत्त्वाच्या घटना शोधता आणि पडताळता येतील असा इतिहास जतन करायचा. किमान Request Initiation, Initiator, Recipient, Accept/Reject निर्णय, निर्णयकर्ता, वेळ, Withdraw/Expiry/Termination आणि कारण (लागू असल्यास) यांची नोंद असावी. जुना इतिहास बदलून किंवा पुसून टाकून नवीन स्थिती दाखवू नये.

---

# 5. Mapping-आधारित Transaction Exchange आणि Data Entry Automation

## 5.1 मूलभूत अर्थ [डोमेन-सामान्य]

Mapping चा Transaction शी संबंध एवढाच आहे की दोन व्यवसायांमध्ये पात्र, परस्पर-संबंधित व्यवहार-दस्तऐवज/नोंदींची माहिती स्वयंचलितरीत्या पोहोचवून प्राप्तकर्त्याला ती पुन्हा हाताने टाइप न करता तपासून स्वीकारण्याची सुविधा देता येते.

**Automatic Transaction** याचा अर्थ दुसऱ्या व्यवसायाच्या डेटामध्ये त्याच्या संमतीशिवाय नोंद करणे, त्याचा Ledger/Inventory बदलणे किंवा त्याच्या वतीने व्यवहार मंजूर करणे असा नाही. येथे “Automatic” हा शब्द मुख्यतः माहितीची देवाणघेवाण आणि पुनः-Data Entry टाळण्यासाठीच्या सहाय्यक प्रक्रियेसाठी आहे; Receiver च्या Acceptance शिवाय त्याच्या Operational Records मध्ये Posting करण्यासाठी नाही.

हा नियम केवळ Accounting किंवा Invoice पुरता मर्यादित नाही. तो लागू आणि समर्थित सर्व संबंधित व्यावसायिक Transaction Documents व Records वर समान तत्त्वाने लागू होतो. Accounting, Sales/Purchase, Returns, Credits/Debits, Inventory, Stock Movement किंवा इतर लागू व्यवहार हे उदाहरणात्मक प्रकार आहेत; यादी संपूर्ण/मर्यादित नाही.

## 5.2 Mapping, Permission आणि Acceptance या स्वतंत्र अटी [डोमेन-सामान्य]

पात्र Transaction Exchange होण्यासाठी खालील अटी एकत्र आवश्यक आहेत:

1. संबंधित Distributor–Shop Mapping अस्तित्वात आणि Active असणे.
2. संबंधित व्यवसायांची स्थिती Automation साठी पात्र असणे.
3. दस्तऐवज तयार/पाठवणाऱ्या User कडे संबंधित व्यवहार तयार व Exchange करण्याची Permission असणे.
4. प्राप्तकर्ता बाजूच्या अधिकृत User कडे संबंधित Document तपासून Accept/Reject करण्याची Permission असणे.
5. Receiver ने स्पष्टपणे Accept करणे.

Mapping यांपैकी कोणतीही Permission किंवा Acceptance ची जागा घेत नाही.

## 5.3 Sender आणि Receiver ची स्वतंत्र नोंद [डोमेन-सामान्य]

- Sender स्वतःच्या व्यवसायाच्या प्रणालीत मूळ Transaction Document तयार करतो.
- त्या Document चा Sender च्या व्यवसायातील परिणाम संबंधित Module च्या नियमांनुसार नोंदवला जातो.
- Mapping द्वारे आवश्यक Document माहिती Receiver पर्यंत पोहोचवली जाते.
- Receiver ला ती तपासणी व निर्णयासाठी Exchange Inbox/Staging मध्ये दिसू शकते.
- Receiver ने Accept करण्यापूर्वी त्या Document ची Accounting, Inventory किंवा संबंधित Operational Posting Receiver च्या Records मध्ये तयार करायची नाही.
- Pending/Exchange Staging नोंद आणि Receiver ची अधिकृत Operational Posting या वेगळ्या संकल्पना आहेत. Staging मध्ये माहिती दिसणे म्हणजे आर्थिक/साठा परिणाम नोंदवला गेला असे नाही.

## 5.4 Receiver Acceptance [डोमेन-सामान्य]

Receiver ने अधिकृतरीत्या Accept केल्यावरच संबंधित Document च्या अनुषंगाने Receiver च्या प्रणालीत योग्य, समतुल्य Business Record/Posting तयार होईल. त्या नोंदीला मूळ Sender Document चा संदर्भ जोडलेला असावा. यामुळे Receiver ला तीच माहिती स्वतंत्रपणे पुन्हा टाइप करावी लागत नाही.

व्यवहाराला व्यावसायिक अर्थाने पूर्ण मानण्यापूर्वी Receiver ची Acceptance आणि संबंधित Receiver-side Posting यशस्वीरीत्या जतन झालेली असणे आवश्यक आहे.

## 5.5 Receiver Rejection [डोमेन-सामान्य]

Receiver ने Document Reject केल्यास:

- Receiver च्या Accounting/Inventory किंवा इतर Operational Records मध्ये त्या Document ची Posting किंवा परिणाम तयार होणार नाही.
- Sender च्या मूळ नोंदी आणि त्यांचे मूळ परिणाम कायम राहतील.
- Rejection ची नोंद Sender च्या संबंधित Records/Exchange Audit मध्ये उपलब्ध असावी.
- Sender ने आवश्यक असल्यास योग्य स्वतंत्र Correction/Compensating Document तयार करावा.
- मूळ Document Delete, Overwrite किंवा इतिहासातून पुसू नये.

### 5.5.1 परिणाम दुरुस्तीचे स्वरूप [डोमेन-सामान्य]

Sender च्या मूळ व्यवहाराचा परिणाम रद्द/समायोजित करण्यासाठी संबंधित Domain मधील योग्य प्रक्रिया वापरावी. उदाहरणार्थ Accounting मध्ये Credit Note, Return किंवा Adjustment; Inventory मध्ये लागू Return, Reversal किंवा Stock Adjustment प्रक्रिया वापरली जाऊ शकते. कोणती प्रक्रिया योग्य आहे हे व्यवहाराच्या प्रकारावर आणि त्या Module च्या नियमांवर अवलंबून असेल. ही उदाहरणे मर्यादित यादी नाहीत.

## 5.6 दोन्ही दिशांनी समान नियम [डोमेन-सामान्य]

Distributor कडून Shop कडे सुरू होणाऱ्या आणि Shop कडून Distributor कडे सुरू होणाऱ्या पात्र Transaction Exchange ला समान मूलभूत नियम लागू होतील. कोणत्या बाजूने Document तयार केले यामुळे Receiver Acceptance, Permission किंवा Data Integrity चे नियम बदलत नाहीत.

## 5.7 Mapping मुळे सर्व डेटा प्रवेश उपलब्ध होत नाही [सामान्य]

Mapping फक्त मंजूर आणि संबंधित Exchange साठी आवश्यक माहितीपर्यंतच उपयोगी आहे. ती दुसऱ्या व्यवसायाच्या संपूर्ण Database, ग्राहक यादी, खाते, Inventory, व्यवहार इतिहास किंवा इतर असंबंधित डेटावर सामान्य प्रवेश देत नाही. प्रत्येक Document Type आणि कृतीसाठी स्वतंत्र Permission व Scope लागू राहतो.

## 5.8 Exchange ची अचूकता आणि पुनरावृत्ती प्रतिबंध [डोमेन-सामान्य]

एकच Source Document Receiver च्या Operational Records मध्ये एकापेक्षा अधिक वेळा पोस्ट होऊ नये. Duplicate Delivery, पुनःप्रयत्न, एकाच वेळी आलेल्या कृती किंवा Network/Processing Failure मुळे Duplicate Posting होऊ नये यासाठी स्थिर Document Identity, Source Reference आणि Idempotent Processing सारखे तांत्रिक संरक्षण आवश्यक आहे. तांत्रिक Retry मुळे Receiver Acceptance टाळता किंवा बनावटरीत्या पूर्ण करता येणार नाही.

---

# 6. Business Lifecycle आणि Mapping-आधारित Automation

## 6.1 Deactivation मुळे Automation थांबणे [डोमेन-सामान्य]

एखाद्या Active Mapping मधील Distributor किंवा Shop यांपैकी कोणतीही एक Business Entity Deactivated झाल्यास त्या Mapping द्वारे होणारे सर्व नवीन Automatic Transaction Exchange थांबवायचे. यामध्ये व्यवहाराच्या दिशेनुसार Distributor-to-Shop आणि Shop-to-Distributor दोन्ही प्रक्रिया समाविष्ट आहेत.

ही अट त्या संबंधित Mapping/Pair साठी लागू आहे. दुसऱ्या स्वतंत्र Mapping Pair च्या व्यवहारांवर याचा परिणाम आपोआप होत नाही.

## 6.2 Mapping संबंध आणि Automation पात्रता वेगळी [डोमेन-सामान्य]

फक्त एका Endpoint च्या Deactivation मुळे Mapping चा इतिहास नष्ट करायचा नाही किंवा त्याला Rejected मानायचे नाही. Deactivation मुळे Automatic Transaction Exchange ची पात्रता तात्पुरती बंद होते. Mapping Terminated करण्याचा अर्थ वेगळा आहे.

## 6.3 Reactivation आणि पूर्वलक्षी व्यवहार [डोमेन-सामान्य]

Distributor/Shop पुन्हा Active झाल्यानंतर पात्र नवीन व्यवहारांसाठी Automatic Transaction Exchange पुन्हा वापरता येईल. परंतु Deactivation कालावधीत घडून गेलेल्या व्यवहारांची नोंद आपोआप मागाहून तयार किंवा Synchronize करायची नाही. अशा व्यवहारांची आवश्यक Data Entry संबंधित व्यवसायाने योग्य प्रक्रियेनुसार हाताने करायची.

## 6.4 Deactivation च्या वेळी आधीच Pending असलेले Exchange Documents [डोमेन-सामान्य]

Deactivation होण्यापूर्वी Receiver कडे पोहोचलेला आणि निर्णयाच्या प्रतीक्षेत असलेला Document Pending/On Hold स्वरूपात सुरक्षित ठेवायचा. Deactivation असताना तो आपोआप Accept, Reject किंवा Receiver च्या Operational Records मध्ये Post होणार नाही. संबंधित Business पुन्हा Active झाल्यावर Receiver कडे त्यावर निर्णय घेण्याची सुविधा पुन्हा उपलब्ध होईल.

हा नियम Deactivation कालावधीत नव्याने घडलेल्या व्यवहारांना Automatic Backfill करण्याची परवानगी देत नाही. Pending पूर्वीचा Exchange Document आणि Deactivation दरम्यान घडलेला नवीन व्यवहार या दोन वेगळ्या परिस्थिती आहेत.

## 6.5 कायमस्वरूपी बंद/समाप्त Business [विशिष्ट]

Business कायमस्वरूपी Closed/Terminated झाल्यास संबंधित Mapping पुढे वापरता येणार नाही; आवश्यकतेनुसार संबंधित Mapping Terminate करायची. आधीचे Mapping आणि Transaction History कायम जतन करायचे. Closed Business किंवा Terminated Mapping वरून नवीन Automatic Exchange सुरू करता येणार नाही.

---

# 7. Authorization, Roles आणि Delegation

## 7.1 Permission आणि Mapping स्वतंत्र [सामान्य]

Permission शिवाय Mapping च्या आधारावर कोणतीही कृती करता येणार नाही. Mapping अस्तित्वात आहे म्हणून व्यवहार तयार करणे, दुसऱ्याच्या वतीने Accept/Reject करणे, दुसऱ्याचे Records बदलणे किंवा असंबंधित माहिती पाहणे यांचा अधिकार मिळत नाही.

## 7.2 Central Permission Definitions [सामान्य]

Permissions ची अधिकृत व्याख्या केंद्रीकृत आणि एकसमान असावी. Permission Definition मध्ये आवश्यकतेनुसार किमान खालील माहिती व्यवस्थापित करता आली पाहिजे:

- Permission Code आणि नाव/वर्णन.
- संबंधित Module/Resource आणि Action.
- लागू Business Context किंवा Hierarchy Level.
- लागू Scope.
- Role ला Assign करता येते का.
- Delegation साठी पात्र आहे का.
- Active/Inactive स्थिती.

नवीन Permission Definition जोडण्यासाठी एकच अधिकृत व्यवस्थापनबिंदू असावा. मात्र नवीन Business Operation प्रत्यक्ष विकसित केल्याशिवाय केवळ Permission नोंदवून ती कृती अस्तित्वात येत नाही. प्रत्येक संवेदनशील API/Operation वर Server-side Authorization आवश्यक आहे.

## 7.3 Roles [सामान्य]

Role हे Permissions चा नियंत्रित संच आहे. Organization/Business संदर्भातील Roles व्यवस्थापित करता येतील. Role तयार करणे किंवा User ला Role देणे यामुळे त्याला मूळ Grantor किंवा लागू Business Scope पेक्षा अधिक अधिकार मिळू नयेत.

Role Permissions आणि User–Business Association या स्वतंत्र संकल्पना आहेत. एखाद्या User ला Role असल्याने तो कोणत्याही Organization/Business च्या डेटावर प्रवेश करू शकतो असे नाही.

## 7.4 Delegation [सामान्य]

Delegation म्हणजे निश्चित Scope मध्ये, निश्चित कालावधीसाठी, विशिष्ट कृतींचे अधिकार दुसऱ्या पात्र व्यक्तीस देणे. Delegation मध्ये Business/Tenant संदर्भ, Actions, वैधता कालावधी आणि Revocation स्पष्ट असावे. Delegation मुळे मूळ अधिकारक्षेत्र वाढवता किंवा दुसऱ्याच्या संपूर्ण डेटावर अधिकार देता येऊ नयेत.

## 7.5 Approval Workflow [सामान्य]

ज्या कृतींसाठी Approval आवश्यक आहे, त्यासाठी Single, Multiple, Sequential किंवा Parallel Approval प्रकार समर्थित करण्याची क्षमता असावी. कोणत्या Operation साठी कोणते Approval लागू होईल, Reviewers कोण असतील आणि आवश्यक मंजुरी किती असतील हे संबंधित नियंत्रित Policy मध्ये स्पष्ट असावे. Approval पूर्ण होण्यापूर्वी संरक्षित कृती अंतिमरित्या लागू करू नये.

---

# 8. Modules, Services आणि Navigation

## 8.1 Module ची स्वतंत्र व्याख्या [सामान्य]

प्रत्येक Module ची स्वतंत्र व्यावसायिक जबाबदारी, Domain Logic, आवश्यक Permissions, लागू Business Context आणि Dependencies स्पष्ट असाव्यात. नवीन Module जोडताना विद्यमान Modules च्या जबाबदाऱ्या अनावश्यकपणे मिसळू नयेत.

## 8.2 Module Registry आणि Navigation [सामान्य]

Module आणि Navigation ची नोंद एकाच ठिकाणी व्यवस्थापित करता येईल अशी Registry/Configuration व्यवस्था असावी. त्यात Module Code/Name, Parent Menu, Icon, Route, आवश्यक Permissions, लागू Context, Visibility आणि Display Order यांसारखी माहिती असू शकते.

Navigation मधील Menu दिसणे ही Security Control नाही. प्रत्येक API आणि Business Operation वर Backend Authorization स्वतंत्रपणे आवश्यक आहे. Module Registry मध्ये नोंद केल्याने त्या Module ची Business Functionality आपोआप तयार होत नाही; त्यासाठी कार्यक्षम Module Code आणि API आवश्यक आहेत.

## 8.3 Service Registry [सामान्य]

Platform Services, त्यांची Categories आणि Capabilities यांची नोंद करता येईल. Service Registry मध्ये Service नोंदवली म्हणजे ती Service प्रत्यक्ष विकसित, Deploy किंवा Operational झाली असे मानता येणार नाही. Service ची नोंदणी आणि Service ची कार्यरत अंमलबजावणी वेगळ्या गोष्टी आहेत.

## 8.4 Scope Definitions [सामान्य]

Ecosystem, Service Provider, Tenant आणि Business Context अशा Scope संकल्पना स्पष्ट व्याख्यांसह वापराव्यात. Scope बदल किंवा नवीन Scope जोडताना त्याचा प्रत्यक्ष Security अर्थ, Data Boundary आणि Enforcement स्पष्ट असणे आवश्यक आहे. फक्त Frontend मध्ये Scope Option जोडल्याने सुरक्षित Scope तयार होत नाही.

---

# 9. Commercial, Subscription आणि Billing संबंधी तत्त्वे

## 9.1 Configurable Commercial Definitions [सामान्य]

Commercial व्यवस्थेत Plans, Plan Versions, Entitlements, Subscriptions, Coverage, Add-ons आणि Add-on Versions यांची स्वतंत्र नोंद व Version History असावी. Plan किंवा Add-on ची नवीन Version तयार केल्याने जुन्या व्यवहारांचा ऐतिहासिक संदर्भ हरवू नये.

## 9.2 Subscription Coverage [विशिष्ट]

Subscription कोणत्या पात्र Business Entities ना लागू आहे हे स्पष्टपणे नोंदवले पाहिजे. Coverage ही Mapping पासून स्वतंत्र आहे. Distributor–Shop Mapping असल्यामुळेच एखाद्या Shop ला Distributor च्या Subscription, Entitlement किंवा इतर हक्कांचा आपोआप लाभ मिळत नाही. लागू Coverage आणि Entitlement नियम स्वतंत्रपणे तपासले जातील.

## 9.3 Renewal आणि Grace Policy [सामान्य]

Renewal Mode, Lead Time, Renewal Terms, Grace Period आणि Grace Policy स्पष्ट व नियंत्रित असाव्यात. Renewal Record तयार होणे आणि संबंधित Subscription प्रत्यक्ष Active होणे या स्वतंत्र घटना आहेत. लागू Activation अटी पूर्ण झाल्याशिवाय Subscription Active मानू नये.

## 9.4 Billing Records आणि Accounting Integrity [डोमेन-सामान्य]

Invoice, Payment, Reversal आणि Ledger नोंदींमध्ये मूळ व्यवहाराचा इतिहास राखला पाहिजे. चुकीचा किंवा नाकारलेला व्यवहार इतिहासातून मिटवण्याऐवजी लागू असलेल्या योग्य Correction/Reversal/Adjustment Document द्वारे दुरुस्त करावा. Subscription Billing Ledger ला संपूर्ण General Accounting Ledger समजू नये.

## 9.5 Currency आणि Locale [सामान्य]

Currency, Decimal Precision, Number/Date Formatting, Locale आणि Time Zone यांना आवश्यकतेनुसार नियंत्रित Configuration चा आधार असू शकतो. आर्थिक गणित आणि Stored Amount चे अचूक प्रतिनिधित्व हे Presentation Format पासून स्वतंत्र व अचूक असावे. नवीन Currency किंवा Accounting व्यवहारासाठी आवश्यक Domain Support नसताना फक्त Display Setting बदलून तो व्यवहार समर्थित झाला असे मानू नये.

---

# 10. Configuration आणि विस्तारक्षमता — बदलण्याजोगे विरुद्ध स्थिर

| घटक | स्थिर/अपरिवर्तनीय भाग | नियंत्रितपणे Configurable/Extensible भाग |
|---|---|---|
| Business Hierarchy | पाच Entity Types, क्रम, Allowed/Prohibited Parent–Child संबंध | Hierarchy Runtime Configuration नाही |
| Distributor/Shop Parent | तयार झाल्यानंतर Organization बदलू शकत नाही | लागू नाही |
| Distributor–Shop Mapping | Mapping हा स्वतंत्र Many-to-Many संबंध; Pair-level नियम | Pending Request Expiry कालावधी |
| Permissions | प्रत्येक प्रत्यक्ष Operation वर Server-side Enforcement | Permission Definitions, Role Assignments, लागू Scope/Delegation Policy |
| Roles | अधिकारक्षेत्र ओलांडता येणार नाही | Role Definitions आणि त्यातील पात्र Permissions |
| Modules | नवीन कार्यक्षमता प्रत्यक्ष विकसित करावी लागेल | Module Metadata, Visibility, Navigation, Display Order |
| Workflows | Business Integrity आणि Security Preconditions | पात्र Approval Stages, Reviewers आणि Policy Settings |
| Services | नोंदणी म्हणजे Service Execution नाही | Service Categories/Definitions/Capabilities ची नोंद |
| Subscription | लागू व्यावसायिक व आर्थिक अटींची वैधता | Plans, Versions, Entitlements, Add-ons, Renewal Policies |
| Credential Policies | प्रत्यक्ष समर्थित Authentication Mechanisms | समर्थित पर्यायांतील Defaults/Policies |
| Rate Limits | सुरक्षा संरक्षण कायम आवश्यक | योग्य मर्यादा नियंत्रित Configuration मधून |
| Audit | महत्त्वाच्या घटनांचा इतिहास नष्ट/Overwrite करायचा नाही | Event Taxonomy आणि तपशीलांचे संरचित व्यवस्थापन |

नवीन Permission, Role, Module किंवा Policy जोडणे शक्य तितके केंद्रीकृत असावे. परंतु नवीन व्यवहार, नवीन API, नवीन Authentication Mechanism किंवा नवीन Domain Operation साठी आवश्यक Code बदल/विकास करणे अपरिहार्य असू शकते. Configuration चा उपयोग नसलेली कार्यक्षमता असल्याचा आभास निर्माण करण्यासाठी करू नये.

---

# 11. डेटा अखंडता, Audit आणि सुरक्षा

## 11.1 Server-side Enforcement [सामान्य]

Frontend मधील Button लपवणे, Menu न दाखवणे किंवा Form Disable करणे हे सुरक्षा नियंत्रण नाही. प्रत्येक संरक्षित API आणि Operation वर Backend ने Authentication, Permission, Scope, Business Status आणि लागू Preconditions तपासले पाहिजेत.

## 11.2 Structural Integrity [विशिष्ट]

Database आणि Backend दोन्ही स्तरांवर निश्चित Hierarchy चे उल्लंघन होऊ नये. चुकीचा Parent Type, एकापेक्षा अधिक Structural Parent, निषिद्ध Parent–Child संबंध किंवा Distributor/Shop चा Organization Parent बदलण्याचा प्रयत्न रोखला पाहिजे. Foreign Key अस्तित्व तपासते; Parent/Child Business Types ची वैधता स्वतंत्रपणे enforce करणे आवश्यक आहे.

## 11.3 Mapping Integrity [विशिष्ट]

Mapping Pair ची ओळख, Pending/Active Duplicate प्रतिबंध, Rejected Pair चा अंतिम प्रतिबंध, दोन्ही Endpoint चे Business Types आणि संबंधित स्थिती यांची वैधता तपासली पाहिजे. समांतर विनंत्यांमुळे Duplicate तयार होऊ नये म्हणून आवश्यक Database Constraints आणि Transactional Protection असावी.

## 11.4 Transaction Integrity [डोमेन-सामान्य]

Receiver Acceptance शिवाय Receiver-side Operational Posting होऊ नये. एका Source Document ची Duplicate Posting होऊ नये. Rejection नंतर Sender चा मूळ Record पुसू नये. Correction मूळ व्यवहाराशी जोडलेल्या स्वतंत्र नोंदींमधून व्हावी.

## 11.5 Audit आणि History [सामान्य]

महत्त्वाच्या Business, Security, Mapping, Approval, Lifecycle, Commercial आणि Transaction घटनांचा तपासता येण्याजोगा इतिहास ठेवावा. इतिहासातून कोण, काय, कधी आणि कोणत्या संदर्भात केले हे समजू शकले पाहिजे. मूळ व्यावसायिक नोंद बदलल्यास पूर्वस्थिती आणि दुरुस्तीचा संदर्भ उपलब्ध असावा.

## 11.6 समांतर प्रक्रिया आणि Idempotency [सामान्य]

एकाच वेळी आलेल्या विनंत्या, Retry, Network Failure किंवा Duplicate API Calls मुळे दोनदा Mapping, दोनदा Posting, दोनदा Payment किंवा परस्परविरोधी Lifecycle बदल होऊ नयेत. योग्य Database Transactions, Locking/Constraints, Idempotency आणि पुनर्प्राप्ती पद्धती वापराव्यात.

---

# 12. मंजूर नियमांवर आधारित पडताळणी उदाहरणे

खालील उदाहरणे भविष्यातील Tests आणि Codebase Gap Analysis साठी अपेक्षित परिणाम स्पष्ट करतात. ती नव्याने स्वतंत्र व्यावसायिक नियम तयार करत नाहीत.

## 12.1 Hierarchy उदाहरणे [विशिष्ट]

| परिस्थिती | अपेक्षित परिणाम |
|---|---|
| Main Portal अंतर्गत Sub-Portal तयार करणे | मान्य |
| Sub-Portal अंतर्गत Organization तयार करणे | मान्य |
| Main Portal अंतर्गत थेट Organization तयार करणे | मान्य |
| Organization अंतर्गत Distributor तयार करणे | मान्य |
| Organization अंतर्गत Shop तयार करणे | मान्य |
| Distributor अंतर्गत Shop तयार करणे | नाकारले पाहिजे |
| Shop अंतर्गत Distributor तयार करणे | नाकारले पाहिजे |
| Organization ला दोन Structural Parents देणे | नाकारले पाहिजे |
| Distributor/Shop चा Organization Parent बदलणे | नाकारले पाहिजे |
| Distributor–Shop Mapping मुळे `parent_id` बदलणे | होऊ नये |

## 12.2 Mapping उदाहरणे [विशिष्ट]

| परिस्थिती | अपेक्षित परिणाम |
|---|---|
| Distributor ने Shop ला Request पाठवली | Pending |
| Shop ने Distributor ला Request पाठवली | Pending |
| Recipient ने Accept केले | Mapping Active |
| Recipient ने Reject केले | Rejected; त्याच Pair साठी पुन्हा Request निषिद्ध |
| त्याच Pair साठी Pending असताना दुसरी Request | नाकारली पाहिजे |
| त्याच Pair साठी Active Mapping असताना नवीन Request | नाकारली पाहिजे |
| Initiator ने Pending Request Withdraw केली | Withdrawn; नवीन Request शक्य |
| Pending Request Expire झाली | Expired; नवीन Request शक्य |
| Active Mapping Terminate केली | Terminated; इतिहास कायम; नवीन Request शक्य |
| Distributor A आणि Shop B वेगवेगळ्या Organizations मध्ये आहेत | Mapping करता येऊ शकते |
| एका Distributor शी अनेक Shops जोडले | मान्य; व्यावसायिक संख्या मर्यादा नाही |
| एका Shop शी अनेक Distributors जोडले | मान्य; व्यावसायिक संख्या मर्यादा नाही |

## 12.3 Transaction Exchange उदाहरणे [डोमेन-सामान्य]

| परिस्थिती | Sender-side परिणाम | Receiver-side परिणाम |
|---|---|---|
| Mapping Active, Permissions वैध, Document पाठवला | मूळ Document/लागू परिणाम नोंदवले जातात | Exchange Inbox/Staging मध्ये निर्णयासाठी उपलब्ध; Operational Posting नाही |
| Receiver ने Accept केले | मूळ Record कायम; परस्पर संदर्भ उपलब्ध | संबंधित Operational Record/Posting तयार; Duplicate नको |
| Receiver ने Reject केले | मूळ Record आणि त्याचा परिणाम कायम; Rejection नोंद | संबंधित Operational Posting/व्यवहार परिणाम नाही |
| Rejection नंतर Correction | योग्य नवीन Credit/Return/Adjustment इत्यादी नोंद | नवीन स्वतंत्र Exchange असल्यास त्याचे स्वतःचे Acceptance आवश्यक |
| Receiver Deactivated असताना Pending Document | मूळ Sender Record कायम | Pending/On Hold; Accept/Reject/Post नाही |
| Receiver पुन्हा Active झाला | ऐतिहासिक व्यवहारांचा Automatic Backfill नाही | आधीपासून Pending असलेल्या Document वर निर्णय शक्य |
| Deactivation काळात नवीन व्यवहार झाला | मूळ Sender च्या नियमांनुसार नोंद | Automatic Exchange नाही; आवश्यक नोंद हाताने |

## 12.4 Transaction Automation मधील उदाहरणांची व्याप्ती

Distributor ने Shop साठी Invoice तयार करणे हे एक उदाहरण आहे. तोच मूलभूत नियम Inventory-संबंधित दस्तऐवज किंवा अन्य पात्र व्यावसायिक व्यवहारांनाही लागू होतो: Source कडील मूळ नोंद कायम; Receiver ला निर्णयासाठी माहिती; Receiver Acceptance नंतरच त्याच्या Operational Records मध्ये संबंधित Entry; Rejection झाल्यास Receiver-side Posting नाही आणि Source-side Correction स्वतंत्र योग्य प्रक्रियेतून.

---

# 13. शब्दकोश (Glossary)

| इंग्रजी संज्ञा | मराठी अर्थ/स्पष्टीकरण |
|---|---|
| Immutable Core | बदल न होणारा मूलभूत आर्किटेक्चरल भाग |
| Business Entity | व्यवसाय/संस्थेची स्वतंत्र ओळख असलेली नोंद |
| Parent–Child | पालक–अधीनस्थ संरचनात्मक संबंध |
| Cardinality | संबंधातील नोंदींची मान्य संख्या/एक-ते-अनेक रचना |
| Many-to-Many | दोन्ही बाजूंना अनेक संबंध असू शकणारी रचना |
| Mapping Pair | विशिष्ट Distributor आणि Shop यांची जोडी |
| Initiator | Request सुरू करणारी बाजू |
| Recipient | Request/Document प्राप्त करणारी बाजू |
| Pending | निर्णयाच्या प्रतीक्षेत |
| Active | कार्यरत/सक्रिय |
| Rejected | नाकारलेले; या Mapping Pair साठी अंतिम प्रतिबंध |
| Withdrawn | Initiator ने मागे घेतलेले |
| Expired | वैधता कालावधी संपलेले |
| Terminated | पूर्वी Active असलेले संबंध समाप्त केलेले |
| Transaction Exchange | व्यवसायांमधील व्यवहार-दस्तऐवज/नोंदींची देवाणघेवाण |
| Staging / Inbox | निर्णयापूर्वीचा तात्पुरता Exchange भाग |
| Operational Posting | प्राप्तकर्ता व्यवसायाच्या अधिकृत व्यवहार नोंदीत Entry |
| Receiver Acceptance | प्राप्तकर्त्याची स्पष्ट संमती |
| Compensating Entry | मूळ नोंद न पुसता तिचा परिणाम दुरुस्त करणारी स्वतंत्र नोंद |
| Idempotency | एकाच कृतीचा पुनःप्रयत्न झाला तरी परिणाम एकदाच लागू राहण्याची हमी |
| Permission | विशिष्ट कृती करण्याचा अधिकृत अधिकार |
| Delegation | निश्चित Scope/कालावधीतील अधिकार सुपूर्द करणे |
| Entitlement | Plan/Subscription अंतर्गत उपलब्ध हक्क किंवा सुविधा |
| Coverage | Subscription/Entitlement कोणत्या पात्र Business Entities ना लागू आहे याची व्याख्या |

---

# 14. दस्तऐवजाच्या वापराबाबत अंतिम नियम

1. हा दस्तऐवज मंजूर संकल्पना आणि व्यावसायिक नियमांचा आधार आहे.
2. यातील [GENERAL], [DOMAIN-GENERAL] आणि [SPECIFIC] वर्गीकरण बदल न करता पुढील Technical Design मध्ये जपायचे.
3. उदाहरणे नियमांचा व्याप कमी करत नाहीत; विशेषतः Transaction Exchange चे नियम Accounting पुरते मर्यादित नाहीत.
4. निश्चित Business Hierarchy आणि तिचे Allowed/Prohibited संबंध Runtime Configuration द्वारे बदलता येणार नाहीत.
5. Distributor–Shop Mapping हा स्वतंत्र संबंध आहे; तो Business Hierarchy, Ownership किंवा Service Provider Relationship बदलत नाही.
6. Mapping हे Transaction Exchange साठी आवश्यक संबंध आहे; ते स्वतः Permission किंवा Receiver Acceptance नाही.
7. Receiver Acceptance शिवाय Receiver-side Operational Posting निषिद्ध आहे.
8. Rejected Mapping Request त्या Pair साठी अंतिम आहे; नवीन Request करून Rejection टाळता येणार नाही.
9. मूळ व्यवहार/दस्तऐवजाचा इतिहास पुसून दुरुस्ती करायची नाही; योग्य स्वतंत्र Correction/Compensating Record वापरायचा.
10. विद्यमान Codebase चे परीक्षण करताना या दस्तऐवजातील मंजूर नियम हे अपेक्षित वर्तन मानायचे. Codebase मधील वेगळे वर्तन आढळल्यास त्याला नियम म्हणून स्वीकारायचे नाही; Gap म्हणून नोंदवायचे.

---

**दस्तऐवज समाप्त — CBMS Approved Core Rules, Version 1.0**
